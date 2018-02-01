<?php

use Icinga\Module\Fileshipper\VendorLoader;

$this->provideHook('director/ShipConfigFiles');
$this->provideHook('director/ImportSource');

VendorLoader::delegateLoadingToIcingaWeb($this->app);