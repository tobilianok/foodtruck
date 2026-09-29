<?php

namespace Tests\Unit;

use App\Support\Receipts\ReceiptParser;
use PHPUnit\Framework\TestCase;

/**
 * Tickets Lidl Plus réels (dématérialisés), transcrits à l'identique.
 */
class LidlReceiptTest extends TestCase
{
    public const CHARMEIL_24_09 = <<<'TXT'
        Ticket de vente
                  Les Grands Champs
                    03110CHARMEIL
                   Ticket de vente
        Article              P.U.EUR Qté    EUR
        Butternut vrac                    2,69 A T
          1,500 kg x 1,79   EUR/kg
             Réduction Lidl Plus          -0,92
         -----------
        Kiwi jaune pièce       0,89  4    3,56 A T
        Courgette 1 kg         2,39  1    2,39 A T
        Brocoli                1,29  1    1,29 A T
        Carottes sachet 1 kg   1,79  1    1,79 A T
        Pomme de terre four    2,99  1    2,99 A T
        Blanc poireau 500 g    2,99  1    2,99 A T
        Aubergine vrac                    1,14 A T
          0,382 kg x 2,99   EUR/kg
        D'un point à 0515014   2,99  1    2,99 A
               Prix en baisse            -0,30
        Boisson Trop Zéro      1,39  4    5,56 A T
        Patate douce                      1,31 A T
          0,438 kg x 2,99   EUR/kg
        Prune rouge                       1,56 A T
          0,446 kg x 3,49   EUR/kg
               Prix en baisse            -0,36
        Boisson énergisante    0,99  1    0,99 A T
        Colossus Energy Drin   0,99  1    0,99 A T
        Colossus Goyave        0,99  1    0,99 A T
        Nombre de lignes: 15
        -----------------------------------------
        A payer                          31,65
                                       30,00 HT
        Total éligible TR (T) :          28,96

        Carte                            31,65
         TVA Taux    MONT.TTC  MONT.TVA   TOTAL HT
          A   5,5%     31,65      1,65      30,00
        -----------------------------------------
        Total Promotion                   1,58
        -----------------------------------------
        ¦            Avec Lidl Plus,              ¦
        ¦     vous avez économisé 0,92 EUR        ¦
        -----------------------------------------
        code-barres .. 08882970522183042409 26
        2970   522183/04/11/02  24.09.26  14:34:24
        Siret: 34326262226433      Code APE: 4711D
          CARTE BANCAIRE
        le 24/09/26 a 14:34:15
        MONTANT
          31,65 EUR
        Pour information:
        207,61 FRF
        TXT;

    public const CHARMEIL_19_09 = <<<'TXT'
        Les Grands Champs
        03110CHARMEIL
        Ticket de vente
        Article              P.U.EUR Qté    EUR
        Harry's Mie Nature     1,61  1    1,61 A T
             Rabais 25%                   -0,41
         -----------
        Banane vrac                       1,52 A T
          1,018 kg x 1,49   EUR/kg
        Nattoyant citron       1,78  1    1,78 B
        Capsules Lungo UTZ     2,79  2    5,58 A T
               Prix en baisse            -0,20
        Nombre de lignes: 4
        -----------------------------------------
        A payer                           9,88
                                        9,16 HT
        Total éligible TR (T) :           8,10

        Carte                             9,88
         TVA Taux   MONT.TTC  MONT.TVA   TOTAL HT
          A   5,5%      8,10      0,42      7,68
          B    20%      1,78      0,30      1,48
        -----------------------------------------
        Total Promotion                   0,61
        2970   857589/02/26/01  19.09.26  16:15:13
        TXT;

    public const CHARMEIL_02_10_25 = <<<'TXT'
                  Les Grands Champs
                  FR-03110 CHARMEIL
                   Ticket de vente
        Article              P.U.EUR Qté    EUR
        LPM calendula&coco     5,99  1    5,99 B T
             Rem LPM                      -2,04
        Potimarron vrac                   2,83 A T
          1,420 kg x 1,99   EUR/kg
             Réduction Lidl Plus          -0,71
         -----------
        Yoplait Pat Patrouil   1,67  1    1,67 A T
        Rösti                  1,85  1    1,85 A T
        Filet mignon          12,19  1   12,19 A T
        Hauts de cuisse poul   4,49  1    4,49 A T
        Beurre doux extra fi   2,35  1    2,35 A T
        Chèvre bûchette 45%    1,73  1    1,73 A T
        Crème dessert vanill   0,89  2    1,78 A T
        Camembert caractère    1,79  1    1,79 A T
        Saucisse de Toulouse   5,89  1    5,89 A T
        Double rôti de porc              12,58 A T
          1,574 kg x 7,99   EUR/kg
        Crottins de chèvre     1,78  1    1,78 A T
        Lardons fumés          1,24  2    2,48 A T
        Pomme de terre 1 kg    0,65  2    1,30 A T
        Tomates pelées         0,55  4    2,20 A T
        Huile d'olive vierge   6,85  1    6,85 A T
        Croûtons à l'ail pou   1,19  1    1,19 A T
        Sanytol désin. linge   6,95  1    6,95 B
        Levure chimique        0,26  2    0,52 A T
        Sucre vanillé          0,79  1    0,79 A T
        Pâté de campagne       1,73  1    1,73 A T
        Navet                             1,23 A T
          0,616 kg x 1,99   EUR/kg
        Poivron rouge vrac                2,47 A T
          0,708 kg x 3,49   EUR/kg
        Carottes sachet 1 kg   1,49  1    1,49 A T
        Patate douce                      1,55 A T
          0,598 kg x 2,59   EUR/kg
        Bouquets garnis        1,69  1    1,69 A T
        Biscuit chocolat       0,97  2    1,94 A T
        Capsules Lungo UTZ     2,69  3    8,07 A T
        Bordeaux Blanc AOP     3,49  1    3,49 B
        Bordeaux élevé Fût     2,99  1    2,99 B
        Monbazillac AOP        5,99  1    5,99 B
        Nombre de lignes: 32
        -----------------------------------------
        A payer                         109,09
                                      100,72 HT
        Total éligible TR (T) :          89,67

        Carte                           109,09
        Total Promotion                   2,75
         TVA Taux    MONT.TTC  MONT.TVA   TOTAL HT
          A   5,5%     85,72      4,47      81,25
          B    20%     23,37      3,90      19,47
        code-barres .. 08882970667602020210 25
        2970   667602/02/18/01  02.10.25  13:44:45
        le 02/10/25 a 13:43:41
        TXT;

    private function parse(string $text): array
    {
        return (new ReceiptParser)->parse($text);
    }

    private function paid(array $result): int
    {
        return array_sum(array_map(fn ($l) => $l['total_cents'] - $l['discount_cents'], $result['lines']));
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

    public function test_ticket_du_24_septembre(): void
    {
        $result = $this->parse(self::CHARMEIL_24_09);

        $this->assertSame('2026-09-24', $result['date'], 'Le code « 522183/04/11/02 » n\'est pas pris pour une date');
        $this->assertSame(3165, $result['total_cents']);
        $this->assertCount(15, $result['lines'], 'Nombre de lignes annoncé par le ticket');
        $this->assertSame(3165, $this->paid($result), 'La somme des lignes (remises déduites) retombe sur « A payer »');

        $butternut = $this->line($result, 'Butternut vrac');
        $this->assertSame(['kg', 1.5, 179, 269, 92, 5.5], [$butternut['quantity_unit'], $butternut['quantity'], $butternut['unit_price_cents'], $butternut['total_cents'], $butternut['discount_cents'], $butternut['vat_rate']]);

        $kiwi = $this->line($result, 'Kiwi jaune pièce');
        $this->assertSame([4.0, 89, 356], [$kiwi['quantity'], $kiwi['unit_price_cents'], $kiwi['total_cents']]);

        $this->assertSame(30, $this->line($result, 'D\'un point à 0515014')['discount_cents']);
        $this->assertSame([0.446, 349, 36], [$this->line($result, 'Prune rouge')['quantity'], $this->line($result, 'Prune rouge')['unit_price_cents'], $this->line($result, 'Prune rouge')['discount_cents']]);
    }

    public function test_ticket_du_19_septembre_avec_tva_20(): void
    {
        $result = $this->parse(self::CHARMEIL_19_09);

        $this->assertSame('2026-09-19', $result['date']);
        $this->assertSame(988, $result['total_cents']);
        $this->assertSame(988, $this->paid($result));
        $this->assertSame(['Harry\'s Mie Nature', 'Banane vrac', 'Nattoyant citron', 'Capsules Lungo UTZ'], array_column($result['lines'], 'label'));
        $this->assertSame(20.0, $this->line($result, 'Nattoyant citron')['vat_rate'], 'Produit d\'entretien : TVA 20 %');
        $this->assertSame(5.5, $this->line($result, 'Banane vrac')['vat_rate']);
        $this->assertSame([2.0, 279, 558, 20], [$this->line($result, 'Capsules Lungo UTZ')['quantity'], $this->line($result, 'Capsules Lungo UTZ')['unit_price_cents'], $this->line($result, 'Capsules Lungo UTZ')['total_cents'], $this->line($result, 'Capsules Lungo UTZ')['discount_cents']]);
    }

    public function test_grand_ticket_du_2_octobre_2025(): void
    {
        $result = $this->parse(self::CHARMEIL_02_10_25);

        $this->assertSame('2025-10-02', $result['date']);
        $this->assertSame(10909, $result['total_cents']);
        $this->assertCount(32, $result['lines']);
        $this->assertSame(10909, $this->paid($result));

        $this->assertSame([1.574, 799, 1258], [$this->line($result, 'Double rôti de porc')['quantity'], $this->line($result, 'Double rôti de porc')['unit_price_cents'], $this->line($result, 'Double rôti de porc')['total_cents']]);
        $this->assertSame(204, $this->line($result, 'LPM calendula&coco')['discount_cents']);
        $this->assertSame(71, $this->line($result, 'Potimarron vrac')['discount_cents']);
        $this->assertSame([4.0, 55], [$this->line($result, 'Tomates pelées')['quantity'], $this->line($result, 'Tomates pelées')['unit_price_cents']]);
        $this->assertSame(20.0, $this->line($result, 'Monbazillac AOP')['vat_rate']);
    }
}
