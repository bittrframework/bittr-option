<?php

use Bittr\Option;

require_once 'src/Option.php';

(new Option('tmp-dir', true, true))->createFolder();
(new Option('tmp-dir/tmp-file1', true, true))->createFile();
(new Option('tmp-dir/tmp-file2', true, true))->createFile();
(new Option('tmp-dir/tmp-file3', true, true))->createFile();

(new Option('tmp-dir', true, true))->copy('new-tmp-dir');
(new Option('new-tmp-dir/tmp-file1', true))->copy('new-tmp-dird/tmp-file1-copy');


//(new Option('tmp-dir', true, true))->copy('new-tmp-dir2');
