<?php

dataset('invalid international phones', [
    'null' => [null],
    'empty' => [''],
    'national number without DDI' => ['11999999999'],
    'unknown DDI' => ['99911999999999'],
    'invalid national number' => ['5511012345678'],
    'too long' => ['5511999999999999'],
    'too short' => ['55'],
    'leading zero' => ['05511999999999'],
    'plus sign' => ['+5511999999999'],
    'punctuation' => ['55 (11) 99999-9999'],
    'letters' => ['55119999a9999'],
    'integer' => [5511999999999],
    'array' => [['5511999999999']],
]);
