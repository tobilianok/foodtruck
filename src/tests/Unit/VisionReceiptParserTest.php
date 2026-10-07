<?php

namespace Tests\Unit;

use App\Support\Receipts\ReceiptParser;
use App\Support\Receipts\VisionReceiptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * v0.19.0 : réponses réelles du modèle (essai du 7 octobre 2026, qwen3-vl:8b-instruct-q8_0 sur le PC de Louis) pour
 * ses 6 tickets Paperless : 472 Leclerc Drive, 473, 474, 476, 477, 478 Lidl Plus. Le code doit retomber au centime
 * sur le total imprimé de chacun, rattacher les pesées, retrouver les poids et convertir les dates.
 */
class VisionReceiptParserTest extends TestCase
{
    private static function answer(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/vision/tickets/'.$name.'.json'), true);
    }

    private static function paid(array $parsed): int
    {
        return ReceiptParser::paidSum($parsed['lines']);
    }

    private static function line(array $parsed, string $label): array
    {
        foreach ($parsed['lines'] as $line) {
            if ($line['label'] === $label) {
                return $line;
            }
        }
        self::fail("Ligne « {$label} » absente");
    }

    public static function tickets(): array
    {
        return [
            '472 Leclerc Drive' => ['472', 9135, '2026-09-28', 34],
            '473 Lidl' => ['473', 988, '2026-09-19', 4],
            '474 Lidl' => ['474', 4183, '2026-08-29', 11],
            '476 Lidl' => ['476', 3165, '2026-09-24', 15],
            '477 Lidl Vichy' => ['477', 7396, '2026-06-06', 23],
            '478 Lidl' => ['478', 10909, '2025-10-02', 32],
        ];
    }

    #[DataProvider('tickets')]
    public function test_les_six_tickets_retombent_sur_leur_total(string $name, int $total, string $date, int $articles): void
    {
        $parsed = (new VisionReceiptParser)->parse(self::answer($name));

        $this->assertSame($total, $parsed['total_cents']);
        $this->assertSame($total, self::paid($parsed), 'Somme des lignes (remises déduites) = total imprimé');
        $this->assertSame($date, $parsed['date']);
        $this->assertSame([], $parsed['unread']);
        $this->assertCount($articles, array_filter($parsed['lines'], fn ($l) => $l['kind'] === 'produit'));
    }

    public function test_ligne_parasite_et_pesee_isolee(): void
    {
        $parsed = (new VisionReceiptParser)->parse(self::answer('473'));

        // « Nombre de lignes: 4 » recopié avec le montant du total : écarté
        $this->assertNotContains('Nombre de lignes: 4', array_column($parsed['lines'], 'label'));
        // « 1,018 kg x 1,49 EUR/kg » recopié à part : rattaché à la banane, pas compté deux fois
        $banane = self::line($parsed, 'Banane vrac');
        $this->assertSame([1.018, 'kg', 149, 152], [$banane['quantity'], $banane['quantity_unit'], $banane['unit_price_cents'], $banane['total_cents']]);
        $this->assertNotContains('1,018 kg x 1,49 EUR/kg', array_column($parsed['lines'], 'label'));
        // Remise rattachée à l'article du dessus
        $this->assertSame(41, self::line($parsed, "Harry's Mie Nature")['discount_cents']);
    }

    public function test_pesees_et_articles_a_la_piece(): void
    {
        $parsed = (new VisionReceiptParser)->parse(self::answer('476'));

        $butternut = self::line($parsed, 'Butternut vrac');
        $this->assertSame([1.5, 'kg', 179, 269, 92], [$butternut['quantity'], $butternut['quantity_unit'], $butternut['unit_price_cents'], $butternut['total_cents'], $butternut['discount_cents']]);
        // « 1,79 EUR/kg » ajouté à tort par le modèle : 1 × 1,79 = 1,79, c'est un sachet à la pièce
        foreach (['Courgette 1 kg', 'Carottes sachet 1 kg', 'Blanc poireau 500 g'] as $label) {
            $this->assertSame(['piece', 1.0], [self::line($parsed, $label)['quantity_unit'], self::line($parsed, $label)['quantity']], $label);
        }
        // Marqué « remise » par le modèle avec un montant positif : c'est un article
        $this->assertSame('produit', self::line($parsed, "D'un point à 0515014")['kind']);
        $this->assertSame(30, self::line($parsed, "D'un point à 0515014")['discount_cents']);

        $ail = self::line((new VisionReceiptParser)->parse(self::answer('474')), 'Ail vrac');
        $this->assertSame([0.064, 'kg', 795, 51], [$ail['quantity'], $ail['quantity_unit'], $ail['unit_price_cents'], $ail['total_cents']]);

        $patates = self::line(($p478 = (new VisionReceiptParser)->parse(self::answer('478'))), 'Pomme de terre 1 kg');
        $this->assertSame(['piece', 2.0, 65], [$patates['quantity_unit'], $patates['quantity'], $patates['unit_price_cents']]);
        $this->assertSame([0.616, 'kg'], [self::line($p478, 'Navet')['quantity'], self::line($p478, 'Navet')['quantity_unit']]);
        // Poids non recopié : déduit du prix ÷ prix au kilo, signalé mais pas « à vérifier »
        $poivron = self::line($p478, 'Poivron rouge vrac');
        $this->assertSame([0.708, 'kg', VisionReceiptParser::NOTE_WEIGHT_DEDUCED, false], [$poivron['quantity'], $poivron['quantity_unit'], $poivron['note'], $poivron['uncertain']]);
    }

    public function test_prix_illisible_deduit_du_total(): void
    {
        // Premier essai : prix du « LPM calendula&coco » resté vide
        $parsed = (new VisionReceiptParser)->parse(self::answer('v2-478'));

        $lpm = self::line($parsed, 'LPM calendula&coco');
        $this->assertSame([599, 204, ReceiptParser::NOTE_DEDUCED, true], [$lpm['total_cents'], $lpm['discount_cents'], $lpm['note'], $lpm['uncertain']]);
        $this->assertSame(10909, self::paid($parsed));
        // Poids absents de ce premier essai : déduits
        $this->assertSame(1.422, self::line($parsed, 'Potimarron vrac')['quantity']);
        $this->assertSame(1.574, self::line($parsed, 'Double rôti de porc')['quantity']);
    }

    public function test_calcul_faux_ou_chiffre_douteux_a_verifier(): void
    {
        $parsed = (new VisionReceiptParser)->parse(['magasin' => 'Lidl', 'date_imprimee' => '01/10/2026', 'total' => '6,49', 'lignes' => [
            ['libelle' => 'Faux calcul', 'quantite' => 2, 'unite' => 'pièce', 'prix_unitaire' => '1,00', 'prix' => '2,50', 'remise' => false, 'detail' => null, 'doute' => false],
            ['libelle' => 'Douteux', 'quantite' => 1, 'unite' => 'pièce', 'prix_unitaire' => null, 'prix' => '3,99', 'remise' => false, 'detail' => null, 'doute' => true],
            ['libelle' => 'Prix illisible A', 'quantite' => 1, 'unite' => 'pièce', 'prix_unitaire' => null, 'prix' => '', 'remise' => false, 'detail' => null, 'doute' => true],
            ['libelle' => 'Prix illisible B', 'quantite' => 1, 'unite' => 'pièce', 'prix_unitaire' => null, 'prix' => '?', 'remise' => false, 'detail' => null, 'doute' => true],
        ]]);

        $this->assertSame([VisionReceiptParser::NOTE_MISMATCH, true], [self::line($parsed, 'Faux calcul')['note'], self::line($parsed, 'Faux calcul')['uncertain']]);
        $this->assertSame([VisionReceiptParser::NOTE_DOUBT, true], [self::line($parsed, 'Douteux')['note'], self::line($parsed, 'Douteux')['uncertain']]);
        // Deux prix illisibles : rien n'est déduit, les lignes sont signalées illisibles
        $this->assertSame(['Prix illisible A', 'Prix illisible B'], $parsed['unread']);
        $this->assertSame('2026-10-01', $parsed['date']);
    }

    /** Défauts relevés à la relecture du code : grammes, quantité décimale, détail « 3 x 0,89 », poids dans un libellé. */
    public function test_cas_limites(): void
    {
        $line = fn (array $l) => $l + ['quantite' => 1, 'unite' => 'pièce', 'prix_unitaire' => null, 'remise' => false, 'detail' => null, 'doute' => false];
        $parsed = (new VisionReceiptParser)->parse(['magasin' => 'Lidl', 'date_imprimee' => null, 'total' => null, 'lignes' => [
            $line(['libelle' => 'Raisin', 'quantite' => 420, 'unite' => 'g', 'prix_unitaire' => '3,99', 'prix' => '1,68']),
            $line(['libelle' => 'Potimarron', 'quantite' => 1.42, 'prix_unitaire' => '1,99', 'prix' => '2,83']),
            $line(['libelle' => 'Kiwi', 'prix_unitaire' => null, 'prix' => '2,67', 'detail' => '3 x 0,89']),
            $line(['libelle' => 'Escalopes 600 g x 2', 'prix' => '6,09']),
        ]]);

        $raisin = self::line($parsed, 'Raisin');
        $this->assertSame([0.42, 'kg', 399, null], [$raisin['quantity'], $raisin['quantity_unit'], $raisin['unit_price_cents'], $raisin['note']]);
        $this->assertSame([1.42, 'kg'], [self::line($parsed, 'Potimarron')['quantity'], self::line($parsed, 'Potimarron')['quantity_unit']]);
        $kiwi = self::line($parsed, 'Kiwi');
        $this->assertSame([3.0, 'piece', 89, null], [$kiwi['quantity'], $kiwi['quantity_unit'], $kiwi['unit_price_cents'], $kiwi['note']]);
        $escalopes = self::line($parsed, 'Escalopes 600 g x 2');
        $this->assertSame([1.0, 'piece', 609], [$escalopes['quantity'], $escalopes['quantity_unit'], $escalopes['total_cents']]);
    }

    public function test_montants_et_dates(): void
    {
        $this->assertSame(2.83, VisionReceiptParser::amount('2,83'));
        $this->assertSame(-0.71, VisionReceiptParser::amount('-0,71'));
        $this->assertSame(4.49, VisionReceiptParser::amount('4.49'));
        $this->assertSame(12.19, VisionReceiptParser::amount('12,19 €'));
        $this->assertSame(-2.04, VisionReceiptParser::amount('2,04-'));
        $this->assertSame(5.0, VisionReceiptParser::amount('5'));
        $this->assertNull(VisionReceiptParser::amount(''));
        $this->assertNull(VisionReceiptParser::amount('2,8?'));

        $this->assertSame('2026-09-24', VisionReceiptParser::date('24.09.26'));
        $this->assertSame('2025-10-02', VisionReceiptParser::date('02/10/2025'));
        $this->assertSame('2026-09-28', VisionReceiptParser::date('2026-09-28'));
        $this->assertSame('2026-09-24', VisionReceiptParser::date('le 24/09/26 à 14:34'));
        $this->assertNull(VisionReceiptParser::date('31.02.26'));
        $this->assertNull(VisionReceiptParser::date('01.01.99'), 'Date future refusée');
        $this->assertNull(VisionReceiptParser::date(null));
    }
}
