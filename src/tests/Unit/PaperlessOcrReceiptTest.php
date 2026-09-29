<?php

namespace Tests\Unit;

use App\Support\Receipts\LeclercDriveParser;
use App\Support\Receipts\ReceiptParser;
use PHPUnit\Framework\TestCase;

/**
 * Textes réels extraits par Paperless (OCR des tickets Lidl Plus, PDF Leclerc Drive),
 * enregistrés tels quels dans tests/Fixtures/paperless.
 */
class PaperlessOcrReceiptTest extends TestCase
{
    public static function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__).'/Fixtures/paperless/'.$name.'.txt');
    }

    private function parse(string $text): array
    {
        return (new ReceiptParser)->parse($text);
    }

    private function line(array $result, string $label): array
    {
        foreach ($result['lines'] as $line) {
            if ($line['label'] === $label) {
                return $line;
            }
        }
        $this->fail("Ligne « {$label} » introuvable parmi : ".implode(' | ', array_column($result['lines'], 'label')));
    }

    public function test_grand_ticket_lidl_lu_par_ocr(): void
    {
        $result = $this->parse(self::fixture('lidl-2025-10-02'));

        $this->assertSame('2025-10-02', $result['date']);
        $this->assertSame(10909, $result['total_cents']);
        $this->assertSame(32, $result['expected_lines'], '« Nombre de lignes: 32 »');
        $this->assertCount(32, $result['lines']);
        $this->assertSame([], $result['unread']);
        $this->assertSame(10909, ReceiptParser::paidSum($result['lines']), 'Somme des lignes = « A payer »');

        // Quantité « 7 », « 71 », « | » pour 1 ; « 1,7/9 » pour 1,79 ; code collé « 5,99BT »
        $this->assertSame([1.0, 1219, 1219], [$this->line($result, 'Filet mignon')['quantity'], $this->line($result, 'Filet mignon')['unit_price_cents'], $this->line($result, 'Filet mignon')['total_cents']]);
        $this->assertSame(179, $this->line($result, 'Camembert caractère')['total_cents']);
        $this->assertSame(178, $this->line($result, 'Crottins de chèvre')['total_cents']);
        $lpm = $this->line($result, 'LPM calendula&coco');
        $this->assertSame([599, 204, 20.0], [$lpm['total_cents'], $lpm['discount_cents'], $lpm['vat_rate']], 'Remise Lidl Plus rattachée, TVA 20 %');

        // Prix unitaire ou total mal lu d'un chiffre : corrigé grâce à la quantité, confirmé par le total du ticket
        $this->assertSame([1.0, 235, 235], [$this->line($result, 'Beurre doux extra fi')['quantity'], $this->line($result, 'Beurre doux extra fi')['unit_price_cents'], $this->line($result, 'Beurre doux extra fi')['total_cents']]);
        $this->assertSame([2.0, 65, 130], [$this->line($result, 'Pomme de terre 1 kg')['quantity'], $this->line($result, 'Pomme de terre 1 kg')['unit_price_cents'], $this->line($result, 'Pomme de terre 1 kg')['total_cents']]);
        $this->assertSame([4.0, 55, 220], [$this->line($result, 'Tomates pelées')['quantity'], $this->line($result, 'Tomates pelées')['unit_price_cents'], $this->line($result, 'Tomates pelées')['total_cents']]);
        $this->assertNull($this->line($result, 'Tomates pelées')['note'], 'Somme exacte : la correction est confirmée');

        // Ligne illisible (« Pâté de campagne 2,7 LL ») : montant déduit du total
        $pate = $this->line($result, 'Pâté de campagne');
        $this->assertSame([173, ReceiptParser::NOTE_DEDUCED, true], [$pate['total_cents'], $pate['note'], $pate['uncertain']]);

        // Pesée illisible (« QG 016 KO © L 99 EUR/Kkg ») : poids inconnu, montant conservé
        $navet = $this->line($result, 'Navet');
        $this->assertSame(['kg', 0.0, 123, ReceiptParser::NOTE_WEIGHT], [$navet['quantity_unit'], $navet['quantity'], $navet['total_cents'], $navet['note']]);

        $roti = $this->line($result, 'Double rôti de porc');
        $this->assertSame(['kg', 1.574, 799, 1258], [$roti['quantity_unit'], $roti['quantity'], $roti['unit_price_cents'], $roti['total_cents']], '« EUR/Kkg »');

        foreach (['Bordeaux Blanc AOP', 'Monbazillac AOP', 'Sanytol désin. linge'] as $label) {
            $this->assertSame(20.0, $this->line($result, $label)['vat_rate'], $label);
        }
    }

    public function test_ticket_lidl_remises_aberrantes(): void
    {
        $result = $this->parse(self::fixture('lidl-2026-09-24'));

        $this->assertSame(['2026-09-24', 3165, 15], [$result['date'], $result['total_cents'], $result['expected_lines']]);
        $this->assertCount(15, $result['lines']);
        $this->assertSame(3165, ReceiptParser::paidSum($result['lines']));

        // « -6,92 », « -6,30 », « -60,36 » : chiffre parasite, la remise ne peut dépasser l'article
        $this->assertSame(92, $this->line($result, 'Butternut vrac')['discount_cents']);
        $this->assertSame(30, $this->line($result, "D'un point à 98515914")['discount_cents']);
        $this->assertSame(36, $this->line($result, 'Prune rouge')['discount_cents']);

        // « 8,99 7 0,99 » : prix unitaire aberrant, le total fait foi ; « 1,/9 » → 1,79 ; « 1,14AT »
        $this->assertSame([1.0, 99, 99], [$this->line($result, 'Colossus Energy Drin')['quantity'], $this->line($result, 'Colossus Energy Drin')['unit_price_cents'], $this->line($result, 'Colossus Energy Drin')['total_cents']]);
        $this->assertSame(179, $this->line($result, 'Carottes sachet 1 kg')['total_cents']);
        $this->assertSame([0.382, 299, 114], [$this->line($result, 'Aubergine vrac')['quantity'], $this->line($result, 'Aubergine vrac')['unit_price_cents'], $this->line($result, 'Aubergine vrac')['total_cents']]);
    }

    public function test_correction_non_confirmee_reste_a_verifier(): void
    {
        // Total du ticket qui ne tombe pas juste : les lignes corrigées restent marquées
        $result = $this->parse(str_replace('A payer 31,65', 'A payer 31,70', self::fixture('lidl-2026-09-24')));

        $colossus = $this->line($result, 'Colossus Energy Drin');
        $this->assertSame([ReceiptParser::NOTE_CORRECTED, true], [$colossus['note'], $colossus['uncertain']]);
        $this->assertNull($this->line($result, 'Kiwi jaune pièce')['note'], 'Ligne cohérente : aucune remarque');
    }

    public function test_plusieurs_lignes_illisibles_signalees(): void
    {
        $text = "Article P.U.EUR Qté EUR\nBeurre doux 2,39 1 2,39 A T\nPâté de campagne 2,7 LL\nJambon 3,5 AI\nNombre de lignes: 3\nA payer 8,00\nA 5,5% 8,00 0,42 7,58";
        $result = $this->parse($text);

        $this->assertCount(1, $result['lines']);
        $this->assertSame(['Pâté de campagne 2,7 LL', 'Jambon 3,5 AI'], $result['unread'], 'Plus d\'une ligne illisible : rien n\'est deviné');
    }

    public function test_bon_de_commande_leclerc_drive(): void
    {
        $text = self::fixture('leclerc-drive-2026-09-28');
        $this->assertTrue(LeclercDriveParser::accepts($text));
        $this->assertFalse(LeclercDriveParser::accepts("E.LECLERC VICHY\nLAIT 1L 1,05\nTOTAL 1,05"), 'Ticket de caisse Leclerc : lecteur générique');

        $result = $this->parse($text);

        $this->assertSame('2026-09-28', $result['date'], 'Date de la commande');
        $this->assertSame(8977, $result['total_cents'], 'Total de la commande moins les économies (l\'avoir est un moyen de paiement)');
        $this->assertCount(34, $result['lines']);
        $this->assertSame([], $result['unread']);
        $this->assertSame(8977, ReceiptParser::paidSum($result['lines']));

        $creme = $this->line($result, 'Crème légère fluide UHT Délisse - 12%mg - 3x20cl');
        $this->assertSame([2.0, 184, 368], [$creme['quantity'], $creme['unit_price_cents'], $creme['total_cents']]);

        // Économies d'un lot réparties sur les deux compotes
        $this->assertSame(79, $this->line($result, 'Compote Andros - Pomme Fraise - 8x100g')['discount_cents']);
        $this->assertSame(79, $this->line($result, 'Compote Andros - Pomme Poire - 8x100g')['discount_cents']);

        // Anti-gaspi : prix d'origine reconstitué, remise déjà déduite
        $cuisses = $this->line($result, 'Cuisses de poulet Maître Coq - 1.4kg');
        $this->assertSame([625, 125], [$cuisses['total_cents'], $cuisses['discount_cents']]);

        // Rubrique non alimentaire
        $mouchoirs = $this->line($result, 'Mouchoirs Blancs 4 plis Caresse - 15 Etuis Pocket x9');
        $this->assertTrue($mouchoirs['non_food']);
        $this->assertSame('Hygiene Beaute', $mouchoirs['section']);
        $this->assertFalse($this->line($result, 'Lait entier Délisse - UHT - Brique : 1L')['non_food']);
        $this->assertSame('Laitier Oeufs Vegetal', $this->line($result, 'Lait entier Délisse - UHT - Brique : 1L')['section']);
    }
}
