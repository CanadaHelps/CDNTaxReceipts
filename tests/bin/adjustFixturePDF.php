<?php
/**
 * This helps adjust the metadata in a pdf fixture file so that it will match
 * each time the test runs. You only need to do this once unless you change
 * the test so that it would produce something different in the pdf.
 *
 * To use this, run your test locally so that it produces a resulting pdf file,
 * then run this script on it as in the help text below.
 * Then rename the resulting file to
 *   Cdntaxreceipts_Tests_Mink_<test class><test function>.pdf
 * where test class and test function are the class and function names of the
 * test you just ran to produce it.
 * Then put it in the phpunit/mink/fixtures folder.
 */

if ($argc < 3) {
  echo "\nUsage: php {$argv[0]} <filename> <mock time string>\n";
  echo "where the mock time string is whatever mock time the test that created the fixture used.\n";
  echo "e.g. php {$argv[0]} receipt.pdf \"2021-01-02 10:11:12\"\n";
}
else {
  $s = file_get_contents($argv[1]);
  // The +10 is because for some reason that's the timezone that ends up
  // in our fixture file.
  $s = preg_replace('/\d{14}\+\d\d/', date('YmdHis', strtotime($argv[2])) . '+10', $s);
  $s = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+\d\d/', date('Y-m-d\TH:i:s', strtotime($argv[2])) . '+10', $s);
  $s = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/', '9e7bde6b-2ad3-c6ba-6656-86ba3cf7b7a2', $s);
  $s = preg_replace('/<[a-f0-9]{32}>/', '<9e7bde6b2ad3c6ba665686ba3cf7b7a2>', $s);
  file_put_contents($argv[1], $s);
}
