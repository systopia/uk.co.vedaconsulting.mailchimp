# Mailchimp sync operations, including tests.

Sync efforts fall into four categories:

1. Push from CiviCRM to Mailchimp.
2. Pull from Mailchimp to CiviCRM.
3. CiviCRM-fired hooks.
4. Mailchimp-fired Webhooks

Note that a *key difference between push and pull*, other than the direction of
authority, is that mapped interest groups can be declared as being allowed to be
updated on a pull or not. This is useful when Mailchimp has no right to change
that interest group, e.g. a group that you identify with a smart group in
CiviCRM. Typically such groups should be considered internal and therefore
hidden from subscribers at all times.

One of the challenges is to *identify the CiviCRM* contact that a mailchimp
member matches. The code for this is centralised in
`CRM_Mailchimp_Sync::guessContactIdSingle()`, which has tests at
`MailchimpApiIntegrationMockTest::testGuessContactIdSingle()`. Look at the
comment block for that test for details of how contats are identified. However,
this is slow and so for the pull operation there's some SQL shortcuts for
efficiency - see The "Pull Mailchimp to CiviCRM Sync for a list" heading below.

## About email selection.

In order to be subscribed, the contact must:

- have an email available
- not be deceased
- not have `is_opt_out` set
- not have `do_not_email` set

In terms of subscribing people from CiviCRM to Mailchimp, it will use the first
available (i.e. not "on hold") email in this order:

1. Specified bulk email address
2. Primary email address
3. Any other email address


## Tests are provided at different levels.

- Unit tests check the logic of certain bits of the system. These can be run
  without CiviCRM or Mailchimp services.

- Integration tests require a CiviCRM install but mock the Mailchimp service.
  This enables testing such as checking that CiviCRM is making the expected
  calls to the API

- Integration tests that run with live Mailchimp. These test that the Mailchimp
  API is behaving as expected, and/or that the use of it is achieving what we
  think it is achieving.

# Push CiviCRM to Mailchimp Sync for a list.

The Push Sync is done by the `CRM_Mailchimp_Sync` class. The steps are:

1. Fetch required data from Mailchimp for all the list's members.
2. Fetch required data from CiviCRM for all the list's CiviCRM membership group.
3. Add those who are not on Mailchimp, and update those whose details are
   different on CiviCRM compared to Mailchimp.
4. Remove from mailchimp those not on CiviCRM.

The test cases are as follows:

## A subscribed contact not on Mailchimp is added.

`testPushAddsNewPerson()` checks this.

## Name changes to subscribed contacts are pushed except deletions.

    CiviCRM  Mailchimp  Result (at Mailchimp)
    --------+----------+---------------------
    Fred                Fred (added)
    Fred     Fred       Fred (no change)
    Fred     Barney     Fred (corrected)
             Fred       Fred (no change)
    --------+----------+---------------------

This logic is tested by `tests/unit/SyncTest.php`

The collection, comparison and API calls  are tested in
`tests/integration/MailchimpApiIntegrationTest.php1`

## Interest changes to subscribed contacts are pushed.

This logic is tested by `tests/unit/SyncTest.php`

The collection, comparison and API calls  are tested in
`tests/integration/MailchimpApiIntegrationTest.php`

## Changes to unsubscribed contacts are not pushed.

This is tested in `tests/integration/MailchimpApiIntegrationTest.php`
in `testPushUnsubscribes()`

## A contact no longer subscribed at CiviCRM should be unsubscribed at Mailchimp.

This is tested in `tests/integration/MailchimpApiIntegrationTest.php`
in `testPushUnsubscribes()`


# Pull Mailchimp to CiviCRM Sync for a list.

The Pull Sync is done by the `CRM_Mailchimp_Sync` class. The steps are:

1. Fetch required data from Mailchimp for all the list's members.
2. Fetch required data from CiviCRM for all the list's CiviCRM membership group.
3. Identify a single contact in CiviCRM that corresponds to the Mailchimp member,
   create a contact if needed.
4. Update the contact with name and interest group changes (only for interests
   that are configured to allow Mailchimp to CiviCRM updates)
5. Remove contacts from the membership group if they are not subscribed at Mailchimp.

The test cases are as follows:

## Test identification of contact by known membership group.

An email from Mailchimp can be used to identify the CiviCRM contact if if
matches among a list of CiviCRM contacts that are in the membership group.

This is done with `SyncIntegrationTest::testGuessContactIdsBySubscribers`

## Test identification of contact by the email only matching one contact.

An email can be matched if it's unique to a particular contact in CiviCRM.

This is done with `SyncIntegrationTest::testGuessContactIdsByUniqueEmail`

## Test identification of contact by email and name match.

An email can be matched along with a first and last name if they all match only
one contact in CiviCRM.

This is done with `SyncIntegrationTest::testGuessContactIdsByNameAndEmail`


## Test that name changes from Mailchimp are properly pulled.

See integration test `testPullChangesName()` and for the name logic see unit test
`testUpdateCiviFromMailchimpContactLogic`.

## Test that interest group changes from Mailchimp are properly pulled.

See integration tests:
- `testPullChangesInterests()` For when the group is configured with update
  permission from Mailchimp to Civi.
- `testPullChangesNonPullInterests()` For when the group is NOT configured with
  update permission.

## Test that contacts unknown to CiviCRM when pulled get added.

See integration test `testPullAddsContact()`.

## Test that contacts not received from Mailchimp but in membership group get removed from membership group.

See integration test `testPullRemovesContacts()`.

# Mailchimp Webhooks

Mailchimp's webhooks are an important part of the system. If they are
functioning correctly then the Pull sync should never need to make any changes.

But they're a nightmare for non-techy users to configure, so now this extension
takes care of them. When you visit the settings page all groups' webhooks are
checked, with errors shown to the user. You can correct a list's webhooks by
editing the CiviCRM group settings. There's a tickbox for doing the webhook
changes which defaults to ticked, and when you save it will ensure everything is
correct.

Tests
- `MailchimpApiIntegrationMockTest::testCheckGroupsConfig`
- `MailchimpApiIntegrationMockTest::testConfigureList`



# Posthook used to immediately add/remove  a single person.

If you *add/remove/delete a single contact* from a group that is associated with a
Mailchimp list then the posthook is used to detect this and make the change at
Mailchimp.

There are several cases that this does not cover (and it's therefore of questionable use):

- Smart groups. If you have a smart group of all with last name Flintstone and
  you change someone's name to Flintstone, thus giving them membership of that
  group, this hook will *not* be triggered (@todo test).

- Block additions. If you add more than one contact to a group, the immediate
  Mailchimp updates are not triggered. This is because each contact requires a
  separate API call. Add thousands and this will cause big problems.

If the group you added someone to was synced to an interest at Mailchimp then
the person's membership is checked. If they are, according to CiviCRM in the
group mapped to that lists's membership, then their interests are updated at
Mailchimp. If they are not currently in the membership CiviCRM group then the
interest change is not attempted to be registered with Mailchimp.

See Tests:

- `MailchimpApiIntegrationMockTest::testPostHookForMembershipListChanges()`
- `MailchimpApiIntegrationMockTest::testPostHookForInterestGroupChanges()`

Because of these limitations, you cannot rely on this hook to keep your list
up-to-date and will always need to do a CiviCRM to Mailchimp Push sync before
sending a mailing.

# Settings page

The settings page stores details like the API key etc.

However it also serves to check the mapped groups and lists are properly set up. Specifically it:

- Checks that the list still exists on Mailchimp
- Checks that the list's webhook is set.
- Checks that the list's webhook "API" setting is off.

Warnings are displayed on screen when these settings are wrong.

These warnings are tested in `MailchimpApiIntegrationMockTest::testCheckGroupsConfig()`.


