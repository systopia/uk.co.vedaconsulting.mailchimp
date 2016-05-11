# Version 2.0

Massive changes to accomodate Mailchimp Api3 which is completely different, and
automated testing capability.

An upgrade hook is added to migrate from versions using Api <3. This must be run
while Api2 is still working, i.e in 2016 according to Mailchimp.

The *post hook* used to fire API calls for all GroupContact changes. I've changed
this to only do so when there is only one contact affected. This hook could be
called with 1000 contacts which would have fired 1000 API calls one after
another, so for stability I removed that 'feature' and for clarity I chose 1 as
the maximum number of contacts allowed.

Most of the fields in the tmp tables are now `NOT NULL`. Having nulls just made
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

The email and name SQL was found buggy so has been rewritten - see tests.
