<?php

namespace Tests\Unit;

use App\Support\Units;
use PHPUnit\Framework\TestCase;

/** v0.6.0 : arrondis pratiques des quantités recalculées. */
class PracticalRoundingTest extends TestCase
{
    public function test_arrondis_par_unite(): void
    {
        $cases = [
            // pièces : entier le plus proche, ½ en dessous de 1, jamais 0
            [1.3, 'piece', 1.0], [1.6, 'piece', 2.0], [1.875, 'piece', 2.0], [0.3, 'piece', 0.5], [0.8, 'piece', 1.0], [0.1, 'piece', 0.5],
            // grammes et millilitres : 1, 5, 10 ou 50 près
            [12.4, 'g', 12.0], [156.25, 'g', 155.0], [187.5, 'g', 190.0], [733, 'g', 730.0], [1234, 'g', 1250.0], [0.2, 'g', 1.0], [312.5, 'ml', 315.0],
            // cl, cuillères, pincées, kg
            [12.4, 'cl', 12.0], [7.3, 'cl', 7.5], [0.625, 'cas', 0.5], [1.3, 'cas', 1.5], [0.2, 'cac', 0.5], [0.4, 'pincee', 1.0], [0.625, 'kg', 0.65], [1.37, 'l', 1.4],
        ];

        foreach ($cases as [$quantity, $unit, $expected]) {
            $this->assertSame($expected, Units::practical($quantity, $unit), "{$quantity} {$unit}");
        }

        $this->assertSame(0.0, Units::practical(0, 'g'));
    }

    public function test_libelles_des_quantites_recalculees(): void
    {
        $this->assertSame('1,25 kg', Units::scaledLabel(1250, 'g'));
        $this->assertSame('1,5 L', Units::scaledLabel(1500, 'ml'));
        $this->assertSame('1,2 L', Units::scaledLabel(120, 'cl'));
        $this->assertSame('155 g', Units::scaledLabel(155, 'g'));
        $this->assertSame('1 ½ c. à soupe', Units::scaledLabel(1.5, 'cas'));
        $this->assertSame('½ c. à café', Units::scaledLabel(0.5, 'cac'));
        $this->assertSame('½ pièce', Units::scaledLabel(0.5, 'piece'));
        $this->assertSame('1 pièce', Units::scaledLabel(1, 'piece'));
        $this->assertSame('3 pièces', Units::scaledLabel(3, 'piece'));
        $this->assertSame('2 ½ verres', Units::scaledLabel(2.5, 'verre'));
        $this->assertSame('13 cl', Units::scaledLabel(13, 'cl'));
    }
}
