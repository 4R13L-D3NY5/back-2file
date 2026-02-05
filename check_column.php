<?php
echo "Checking 'grupos' table schema...\n";
$exists = Schema::hasColumn('grupos', 'carrera_id');
if ($exists) {
    echo "Column 'carrera_id' EXISTS.\n";
} else {
    echo "Column 'carrera_id' MISSING.\n";
}
