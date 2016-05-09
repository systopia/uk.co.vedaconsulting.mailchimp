<?php
$classes_root =  implode(DIRECTORY_SEPARATOR,[dirname(dirname(__DIR__)), 'CRM', 'Mailchimp', '']);
require $classes_root . 'Utils.php';

class UtilsTest extends \PHPUnit_Framework_TestCase {

  public function testSplitGroupTitles() {
    $group_details = [
      1 => ['civigroup_title' => 'aye'],
      3 => ['civigroup_title' => 'cee'],
      4 => ['civigroup_title' => 'dee,eee'], // Title with comma.
    ];
    $cases = [
      ['aye,bee',  [1]],
      ['aye,bee,cee', [1,3]],
      ['aye,bee,cee,dee', [1,3]],
      ['aye,bee,cee,dee,eee', [1,3,4]],
      ['', []],
      ];
    foreach ($cases as $case) {
      $result = CRM_Mailchimp_Utils::splitGroupTitles($case[0], $group_details);
      $this->assertEquals($case[1], $result);
    }
  }
}
