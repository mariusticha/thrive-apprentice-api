<?php

declare(strict_types=1);

use Brain\Monkey;

/*
 * Pest.php — applied to all tests under tests/Unit.
 * Boots Brain\Monkey before each test and tears it down after.
 */

uses()
    ->beforeEach(function (): void {
        Monkey\setUp();
    })
    ->afterEach(function (): void {
        Monkey\tearDown();
    })
    ->in('Unit');
