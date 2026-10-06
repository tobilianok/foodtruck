<?php

namespace App\Support\RecipeScan;

use RuntimeException;

/**
 * v0.18.0 : Ollama injoignable (PC éteint, Ollama arrêté pour une partie de jeu…). Ce n'est pas un échec de lecture :
 * la fiche attend, sans limite de tentatives, et la lecture est retentée régulièrement.
 */
class VisionUnavailable extends RuntimeException
{
}
