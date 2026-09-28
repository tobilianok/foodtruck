<?php

namespace Tests\Unit;

use App\Models\Ingredient;
use App\Support\UnitConversionException;
use App\Support\Units;
use PHPUnit\Framework\TestCase;

class UnitsTest extends TestCase
{
    private function ingredient(string $base, ?float $pieceWeight = null, ?float $density = null): Ingredient
    {
        return new Ingredient(['name' => 'Test', 'base_unit' => $base, 'piece_weight_g' => $pieceWeight, 'density' => $density]);
    }

    public function test_meme_dimension(): void
    {
        $lait = $this->ingredient('ml', null, 1.03);
        $this->assertSame(200.0, Units::toBase(20, 'cl', $lait));
        $this->assertSame(1500.0, Units::toBase(1.5, 'l', $lait));
        $this->assertSame(45.0, Units::toBase(3, 'cas', $lait));
        $this->assertSame(250.0, Units::toBase(0.25, 'kg', $this->ingredient('g')));
    }

    public function test_pieces_et_poids(): void
    {
        $carotte = $this->ingredient('g', 125);
        $this->assertSame(375.0, Units::toBase(3, 'piece', $carotte));

        $oeuf = $this->ingredient('piece', 55);
        $this->assertSame(2.0, Units::toBase(110, 'g', $oeuf));
    }

    public function test_volume_et_poids_par_la_densite(): void
    {
        $farine = $this->ingredient('g', null, 0.55);
        $this->assertEqualsWithDelta(110.0, Units::toBase(200, 'ml', $farine), 0.001);

        $lait = $this->ingredient('ml', null, 1.03);
        $this->assertEqualsWithDelta(100.0, Units::toBase(103, 'g', $lait), 0.001);
    }

    public function test_donnee_manquante(): void
    {
        $this->expectException(UnitConversionException::class);
        Units::toBase(2, 'piece', $this->ingredient('g'));
    }

    public function test_affichage(): void
    {
        $this->assertSame('1,5 kg', Units::format(1500, 'g'));
        $this->assertSame('750 g', Units::format(750, 'g'));
        $this->assertSame('1 L', Units::format(1000, 'ml'));
        $this->assertSame('20 cl', Units::format(200, 'ml'));
        $this->assertSame('15 ml', Units::format(15, 'ml'));
        $this->assertSame('6 pièces', Units::format(6, 'piece'));
        $this->assertSame('1 pièce', Units::format(1, 'piece'));
    }
}
