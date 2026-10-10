<?php

arch('identity has no administration dependency')
    ->expect('App\\Modules\\Identity')
    ->not->toUse('App\\Modules\\Administration');

arch('administration has no identity implementation dependency')
    ->expect('App\\Modules\\Administration')
    ->not->toUse('App\\Modules\\Identity');

arch('modules do not depend on legacy HTTP controllers')
    ->expect('App\\Modules')
    ->not->toUse('App\\Http\\Controllers');
