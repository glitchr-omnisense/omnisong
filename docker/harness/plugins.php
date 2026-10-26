<?php

/**
 * Every catalogue package: its slug (omnisong/<slug>, github.com/glitchr-omnisense/omnisong-<slug>),
 * its tests' namespace and its factory class.
 */
return [
    'odesli' => ['Omnisong\\Odesli\\Tests\\', 'Omnisong\\Odesli\\OdesliCatalogFactory'],
    'itunes' => ['Omnisong\\Itunes\\Tests\\', 'Omnisong\\Itunes\\ItunesCatalogFactory'],
];
