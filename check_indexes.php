<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$indexes = DB::select("SHOW INDEXES FROM asignaturas");
foreach ($indexes as $index) {
    if (!$index->Non_unique) {
        echo "UNIQUE INDEX: " . $index->Key_name . " ON column: " . $index->Column_name . "\n";
    }
}
