# Mailchimp Code Overview and Tests.

# Push CiviCRM to Mailchimp Sync for a list.

Syncing a list is done by the `CRM_Mailchimp_Sync` class.

The steps are

1. Fetch required data from Mailchimp for all the list's members.
2. Fetch required data from CiviCRM for all the list's CiviCRM membership group.
3. Remove those that are on Mailchimp but not in CiviCRM.
4. Add those who are not on Mailchimp, and update those whose details are
   different on CiviCRM compared to Mailchimp.

# posthook used to immediately add/remove a single person.

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

- `testPostHookForMembershipListChanges()` 
- `testPostHookForInterestGroupChanges()`

Because of these limitations, you cannot rely on this hook to keep your list
up-to-date and will always need to do a CiviCRM to Mailchimp Push sync before
sending a mailing.

# Tests

Tests are provided at different levels.

- Unit tests check the logic of certain bits of the system. These can be run
  without CiviCRM or Mailchimp services.

- Integration tests require a CiviCRM install but mock the Mailchimp service.
  This enables testing such as checking that CiviCRM is making the expected
  calls to the API

- Integration tests that run with live Mailchimp. These test that the Mailchimp
  API is behaving as expected, and/or that the use of it is achieving what we
  think it is achieving.
