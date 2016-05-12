# Version 2.0

Massive changes to accomodate Mailchimp Api3 which is completely different, and
automated testing capability.

An upgrade hook is added to migrate from versions using Api <3. This must be run
while Api2 is still working, i.e in 2016 according to Mailchimp.

These changes have been made by Rich Lott / artfulrobot.uk with thanks to the
*Sumatran Organutan Society* for funding a significant chunk of the work.

Added this markdown changelog :-)

## Contact and email selection

Contacts must now

- have an email available
- not be deceased (new)
- not have `is_opt_out` set
- not have `do_not_email` set

The system will prefer the bulk email address instead of the primary one.
If no bulk one is available, then it will pick the primary, or if that's not
there either (?!) it will pick any.

## CiviCRM post hook changes

The *post hook* used to fire API calls for all GroupContact changes. I've changed
this to only do so when there is only one contact affected. This hook could be
called with 1000 contacts which would have fired 1000 API calls one after
another, so for stability I removed that 'feature' and for clarity I chose 1 as
the maximum number of contacts allowed.


## Identifying contacts in CiviCRM from Mailchimp

Most of the fields in the tmp tables are now *`NOT NULL`*. Having nulls just made
things more complex and we don't need to distinguish different types of
not-there data.

A new method is added to identify the CiviCRM contact Ids from Mailchimp details
that looks to the subscribers we're expecting to find. This solves the issue
when two contacts (e.g. related) are in CiviCRM with the same email, but only
one of them is subscribed to the list - now it will pick the subscriber. This
test ought to be the fastest of the methods, so it is run first.

The email-is-unique test to identify a contact has been modified such that if
the email is unique to a particular contact, we guess that contact. Previously
the email had to be unique in the email table, which excludes the case that
someone has the same email in several times (e.g. once as a billing, once as a
bulk...).

The email and name SQL for 'guessing' the contact was found buggy by testing so
has been rewritten - see tests.


## Group settings page

Re-worded integration options for clarity. Added fixup checkbox, default ticked.
On saving the form, if this is ticked, CiviCRM will ensure the webhook settings
are correct at Mailchimp.

## Mailchimp Settings Page

Checks all lists including a full check on the webhook config.
