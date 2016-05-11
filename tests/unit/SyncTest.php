<?php
/**
 * Test CRM_Mailchimp_Sync.
 */
$classes_root =  implode(DIRECTORY_SEPARATOR,[dirname(dirname(__DIR__)), 'CRM', 'Mailchimp', '']);
require $classes_root . 'Sync.php';

class SyncTest extends \PHPUnit_Framework_TestCase {

  /**
   *
   */
  public function testUpdateMailchimpFromCiviLogic() {
    $cases = [
      [
        'label' => 'Test no changes (although this case should never actually be used.)',
        'civi' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // First names...
      [
        'label' => 'Test change first name',
        'civi' => ['first_name'=>'New', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['FNAME' => 'New']],
      ],
      [
        'label' => 'Test provide first name',
        'civi' => ['first_name'=>'Provided', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['FNAME' => 'Provided']],
      ],
      [
        'label' => 'Test noclobber first name',
        'civi' => ['first_name'=>'', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Same for last name...
      [
        'label' => 'Test change last name',
        'civi' => ['first_name'=>'x', 'last_name'=>'New', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['LNAME' => 'New']],
      ],
      [
        'label' => 'Test provide last name',
        'civi' => ['first_name'=>'x', 'last_name'=>'Provided', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['LNAME' => 'Provided']],
      ],
      [
        'label' => 'Test noclobber last name',
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Interests
      [
        'label' => 'Test Interest changes for adding new person with no interests.',
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:0:{}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      [
        'label' => 'Test Interest changes for adding new person with interests.',
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['interests' => ['aabbccddee'=>TRUE]],
      ],
      [
        'label' => 'Test Interest changes for existing person with same interests.', 
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'expected' => [],
      ],
      [
        'label' => 'Test Interest changes for existing person with different interests.', 
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:0;}'],
        'expected' => ['interests' => ['aabbccddee'=>TRUE]],
      ],
    ];

    foreach ($cases as $case) {
      extract($case);
      $result = CRM_Mailchimp_Sync::updateMailchimpFromCiviLogic($civi, $mailchimp);
      $this->assertEquals($expected, $result, "FAILED: $label");
    }
  }
  /**
   *
   */
  public function testUpdateCiviFromMailchimpContactLogic() {
    $cases = [
      [
        'label'     => 'Test no changes',
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'x', 'last_name'=>'y'],
        'expected' => [],
      ],
      // First names...
      [
        'label'     => 'Test first name changes',
        'mailchimp' => ['first_name'=>'a', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'x', 'last_name'=>'y'],
        'expected'  => ['first_name'=>'a'],
      ],
      [
        'label'     => 'Test first name provide',
        'mailchimp' => ['first_name'=>'a', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'',  'last_name'=>'y'],
        'expected'  => ['first_name'=>'a'],
      ],
      [
        'label'     => 'Test first name no clobber',
        'mailchimp' => ['first_name'=>'', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'x',  'last_name'=>'y'],
        'expected'  => [],
      ],
      // Last names..
      [
        'label'     => 'Test last name changes',
        'mailchimp' => ['last_name'=>'a', 'first_name'=>'y'],
        'civi'      => ['last_name'=>'x', 'first_name'=>'y'],
        'expected'  => ['last_name'=>'a'],
      ],
      [
        'label'     => 'Test last name provide',
        'mailchimp' => ['last_name'=>'a', 'first_name'=>'y'],
        'civi'      => ['last_name'=>'',  'first_name'=>'y'],
        'expected'  => ['last_name'=>'a'],
      ],
      [
        'label'     => 'Test last name no clobber',
        'mailchimp' => ['last_name'=>'', 'first_name'=>'y'],
        'civi'      => ['last_name'=>'x',  'first_name'=>'y'],
        'expected'  => [],
      ],
    ];

    foreach ($cases as $case) {
      extract($case);
      $result = CRM_Mailchimp_Sync::updateCiviFromMailchimpContactLogic($mailchimp, $civi);
      $this->assertEquals($expected, $result, "FAILED: $label");
    }
  }
}
