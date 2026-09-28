<?php

namespace App\Support;

use RuntimeException;

/** Conversion impossible faute de donnée (densité ou poids d'une pièce). */
class UnitConversionException extends RuntimeException
{
}
