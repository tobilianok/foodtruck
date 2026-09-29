<?php

namespace Tests\Unit;

use App\Support\Receipts\ReceiptParser;
use PHPUnit\Framework\TestCase;

class ReceiptParserTest extends TestCase
{
    private function parse(string $text): array
    {
        return (new ReceiptParser)->parse($text);
    }

    private function labels(array $result): array
    {
        return array_column($result['lines'], 'label');
    }

    public function test_ticket_type_hypermarche(): void
    {
        $result = $this->parse(<<<'TXT'
            E.LECLERC VICHY
            12 AVENUE DE LA REPUBLIQUE
            TEL 04 70 00 00 00
            28/09/2026  18:42  CAISSE 12
            LAIT 1/2 ECR UHT MDD 1L          1,05 €  A
            LAIT 1/2 ECR UHT MDD 1L
               2 x 1,05                      2,10 €  A
            BEURRE DOUX 250G                 2,69 €  A
            CAROTTES VRAC
               0,856 kg x 1,69 EUR/kg         1,45 €  A
            OEUFS PLEIN AIR X12              3,99 €  A
            REMISE IMMEDIATE                -0,50 €
            SAC CABAS                        0,10 €  B
            ================================
            TOTAL                            10,88 €
            CB                               10,88 €
            TVA A 5,5%   0,58
            MERCI DE VOTRE VISITE
            TXT);

        $this->assertSame('2026-09-28', $result['date']);
        $this->assertSame(1088, $result['total_cents']);
        $this->assertSame(['LAIT 1/2 ECR UHT MDD 1L', 'LAIT 1/2 ECR UHT MDD 1L', 'BEURRE DOUX 250G', 'CAROTTES VRAC', 'OEUFS PLEIN AIR X12', 'SAC CABAS'], $this->labels($result));

        [$lait, $lait2, $beurre, $carottes, $oeufs] = $result['lines'];
        $this->assertSame([1.0, 105, 105], [$lait['quantity'], $lait['unit_price_cents'], $lait['total_cents']]);
        $this->assertSame([2.0, 105, 210], [$lait2['quantity'], $lait2['unit_price_cents'], $lait2['total_cents']]);
        $this->assertSame(269, $beurre['total_cents']);
        $this->assertSame(['kg', 0.856, 169, 145], [$carottes['quantity_unit'], $carottes['quantity'], $carottes['unit_price_cents'], $carottes['total_cents']]);
        $this->assertSame([399, 50], [$oeufs['total_cents'], $oeufs['discount_cents']], 'La remise se rattache à l\'article précédent');

        $sum = array_sum(array_map(fn ($l) => $l['total_cents'] - $l['discount_cents'], $result['lines']));
        $this->assertSame(1088, $sum, 'La somme des lignes lues retombe sur le total du ticket');
    }

    public function test_ticket_type_discount_avec_code_tva(): void
    {
        $result = $this->parse(<<<'TXT'
            LIDL
            EUR
            Lait demi-écrémé 1L        0,99 B
            Farine de blé T55          0,79 B
            Pommes Golden              2,39 B
              1,196 kg x 2,00 EUR/kg
            Yaourt nature 4x125g       0,89 B
            3 x 0,89
            Réduction Lidl Plus       -0,40
            Summe
            A payer                    6,94
            Rendu                      0,00
            12.09.26 17:03
            TXT);

        $this->assertSame(694, $result['total_cents']);
        $this->assertSame('2026-09-12', $result['date']);
        $this->assertSame(['Lait demi-écrémé 1L', 'Farine de blé T55', 'Pommes Golden', 'Yaourt nature 4x125g'], $this->labels($result));

        [, , $pommes, $yaourt] = $result['lines'];
        $this->assertSame(['kg', 1.196, 200, 239], [$pommes['quantity_unit'], $pommes['quantity'], $pommes['unit_price_cents'], $pommes['total_cents']]);
        $this->assertSame([3.0, 89, 267, 40], [$yaourt['quantity'], $yaourt['unit_price_cents'], $yaourt['total_cents'], $yaourt['discount_cents']]);
    }

    public function test_primeur_poids_sur_la_meme_ligne_et_erreurs_ocr(): void
    {
        $result = $this->parse(<<<'TXT'
            MORIN FRUITS ET LEGUMES
            Potimarron 1,320 kg x 2,50 €/kg   3,30
            Poireaux 0,640 kg x 2,90 €/kg     1,86
            Persil botte                      1,O0
            Total                             6,16
            TXT);

        $this->assertSame(616, $result['total_cents']);
        $this->assertSame(['Potimarron', 'Poireaux', 'Persil botte'], $this->labels($result));
        $this->assertSame([1.32, 250, 330], [$result['lines'][0]['quantity'], $result['lines'][0]['unit_price_cents'], $result['lines'][0]['total_cents']]);
        $this->assertSame(100, $result['lines'][2]['total_cents'], 'O lu à la place de 0');
    }

    public function test_quantite_et_prix_unitaire_sur_la_meme_ligne(): void
    {
        $result = $this->parse("CREME LIQ 20CL 3 x 0,99          2,97\nCHOCOLAT NOIR 2 X 2,19\nTOTAL 7,35");

        $this->assertSame([3.0, 99, 297], [$result['lines'][0]['quantity'], $result['lines'][0]['unit_price_cents'], $result['lines'][0]['total_cents']]);
        $this->assertSame('CREME LIQ 20CL', $result['lines'][0]['label']);
        $this->assertSame([2.0, 219, 438], [$result['lines'][1]['quantity'], $result['lines'][1]['unit_price_cents'], $result['lines'][1]['total_cents']]);
    }

    public function test_normalisation(): void
    {
        $this->assertSame('LAIT 1/2 ECREME 1L', ReceiptParser::normalize('  Lait 1/2 écrémé — 1L '));
        $this->assertSame(ReceiptParser::normalize('OEUFS PLEIN-AIR X12'), ReceiptParser::normalize('Œufs plein air x12'));
    }
}
