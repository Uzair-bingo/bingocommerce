# ADR-048 — Portal to BingoCommerce single sign-on (SSO) for Hall Super Admins

- Date: 2026-10-12
- Status: **Approved** by the user on 2026-10-13. **Implemented and verified
  in local development on 2026-10-14; not deployed to production.** The
  decisions below were confirmed by the user in discussion between 2026-10-09
  and 2026-10-12, and the written record was reviewed and approved on
  2026-10-13.
- Implementation: BingoCommerce Phases 1, 2, 4a and 4b plus the security-review
  fix (branch `ssoImplementation`); Portal Phases 3a and 3b (Portal branch
  `ssoImplementation`). A user-performed browser test on 2026-10-14 passed:
  first and repeat SSO login, profile without password fields, "Managed by
  Portal", password login refused, email conflict refused, Global Admin
  without SSO, and an ordinary customer unchanged. Before production, every
  item in section 13 (Rollout) and the environment checks in section 19
  (HTTPS and client IP behind any proxy, return-path logging) must be done,
  with new production keys.
- Scope: BingoCommerce (this repository) and Bingo Portal
  (`bingo_portal`). The Portal side is summarised in the Portal's
  `docs/BINGOCOMMERCE_INTEGRATION.md`; this file is the joint record.
- Business rule: BR-085 (DECISIONS.md) records the business rules in short form.
- Table, endpoint, action and setting names in this record are proposals. They
  may be renamed during implementation without changing the rules.

## 1. Decisions confirmed by the user

| # | Decision |
|---|---|
| 1 | A Portal user with the `super_admin` role on a Hall (the **Hall Super Admin**) becomes **Hall Manager** of that Hall's store. |
| 2 | Their store account is **created automatically** on their first SSO login. |
| 3 | A person is identified by Portal identity, **never by email**. |
| 4 | SSO accounts **cannot sign in with a password** or any other credential. Only through the Portal. |
| 5 | Each SSO login works **only on the Hall store it was opened for**. |
| 6 | Logged out after **30 minutes without activity**, or **1 hour at most**. |
| 7 | **Removed in the Portal:** no new logins; an open session ends by rule 6 (accepted business risk: up to 1 hour). |
| 8 | **SSO accounts protected in the store:** every SSO account (username starting with `portal-`) is shown as "Managed by Portal". Hall Managers (employees) cannot remove it or change its role; only a Platform Admin can. The owner's store access is controlled only from the Portal. (Replaces the earlier "blocked until a Platform Admin allows them again" decision; no block is needed.) |
| 9 | **Email already used in the store:** refused; the person must use a different Portal email. |
| 10 | **Portal Global Admin is not part of SSO.** They keep the platform admin login. |
| 11 | **No MFA in v1** (written exception to ADR-012). |
| 12 | Built in-house: no new plugin, library or external identity product. |
| 13 | The Hall Super Admin's **existing "Open Ecommerce Store" button** in the Portal now signs them in to the store admin dashboard. |
| 14 | The Global Admin's existing "Open Store" button is **unchanged** (opens the public website, no SSO). |
| 15 | **One Hall at a time per browser.** Opening a second Hall's store in the same browser signs the person out of the first. |
| 16 | **Linked store account deleted:** SSO refuses with "contact support". It never creates a replacement automatically. |
| 17 | **Portal restore protection:** the store saves the Portal user's creation time with the link; a mismatch is refused and an audit entry is marked for support review. |
| 18 | **Password accounts created by an SSO Hall Manager** through the Hall Staff screen are normal staff accounts. They survive the person's Portal removal. This is accepted and documented (section 9). |
| 19 | **Start requests are limited** per IP address, old login attempts are deleted on every start, and auditing begins at the exchange step. |
| 20 | **Hall transfer in the Portal** gives the new owner the `super_admin` role on that Hall and removes it from the previous owner, who then has **no access to that Hall** in the Portal (their other Halls are unaffected). |
| 21 | **Emergency removal:** Remove Portal access first, wait two minutes, then remove the store role. This wait reduces the chance of an unfinished login restoring access, but does not guarantee cancellation of a delayed login. This is an accepted v1 limitation. (Section 9, rule 5.) |
| 22 | **The SSO token is the on/off switch.** Removing it from the store's configuration turns SSO off; there is no separate setting. The SSO code is never uninstalled. |

## 2. Terms used in this record

- **Store:** the Hall's BingoCommerce WordPress site.
- **Hall Super Admin:** a Portal user holding the `super_admin` role for one
  specific Hall in the Portal's `hall_user` role list.
- **Portal installation ID:** a short fixed identifier (lowercase letters and
  digits, at most 20 characters) of the one authoritative Portal environment,
  configured on both systems. It stops user numbers from a different Portal
  environment being treated as the same people. It does **not** protect
  against a restore of the same Portal database; the Portal user creation time
  does that.
- **Portal user creation time:** the Portal's `users.created_at` value for the
  person, always sent in UTC in one fixed format (for example
  `2026-10-13T14:05:09Z`) and compared exactly. It is saved with the link and
  compared on every login. After a Portal
  database restore, a reused user number has a different creation time.
- **Login attempt:** a short-lived record the store creates when a login
  starts. It holds a random **attempt reference**, the fingerprint of a
  **browser secret**, a **secret proof** and the Hall the attempt belongs to.
- **Browser secret:** a random value kept **only** in a private cookie in the
  browser that started the login. It is never put in any link. The store keeps
  only its fingerprint (SHA-256). It proves that the browser returning from the
  Portal is the same browser that started the login. The attempt reference, by
  contrast, travels in links and is not secret.
- **SSO reference:** one random identifier created when a login starts and
  stored in the details of every related audit record on both systems, to join
  them. (The Portal document calls it the operation ID.) It is not the audit
  table's own operation ID.
- **Secret proof:** a random value that never leaves the store server except in
  the direct server-to-server exchange. The Portal receives only its
  fingerprint (SHA-256) when the code is issued.
- **One-time code:** a short-lived value the Portal gives the browser after
  checking the person. It is useless without the matching secret proof.
- **SSO token:** a Portal machine credential used only by the store's
  server-to-server code exchange. It is separate from the existing snapshot
  (sync) token.
- **Portal-identity username:** the store account's username, built from the
  Portal identity, for example `portal-<installation>-<Portal user ID>`.
  Wherever this record says a username "starts with `portal-`" or is a
  "`portal-` username", it means **exactly this format**, matched without
  regard to letter case: `portal-`, 1–20 lowercase letters or digits, `-`, and
  a Portal user number (`^portal-[a-z0-9]{1,20}-[1-9][0-9]*$`). Other
  usernames that merely begin with `portal-` are ordinary accounts and are not
  affected. Example: a customer with the email `portal-mike@gmail.com` gets
  the automatic username `portal-mike`, which is a normal account.
  **Accepted rare case:** if `portal-mike` is already taken, WooCommerce adds
  random digits to a second such customer's automatic username (for example
  `portal-mike-1234`), which can match this format and be refused. This needs
  two customers whose emails start with the same `portal-…` name; a retry
  usually succeeds because WooCommerce picks new digits. No extra code is
  added for it.
- **"Managed by Portal" label:** the store's Hall Staff screen marks every SSO
  account (username starting with `portal-`) as "Managed by Portal".

## 3. Who gets access

1. Only a Portal user with the `super_admin` role **on that specific Hall** in
   the Portal's `hall_user` role list.
2. **SSO follows the current Portal role list for that Hall.** The Hall's
   owner field (`halls.user_id`) is never used.
3. The account must be **active** (`status` Active and `is_active`) and its
   **email verified** in the Portal.
4. **Portal Global Admin is excluded,** even if they also hold `super_admin`
   on a Hall. The Portal's `User::hasRoleInHall()` returns true for Global
   Admins on every Hall (`app/Models/User.php:231-236`), so the SSO check must
   read the Hall role list directly and refuse `global_role = global_admin`.
5. A Portal `global_role = super_admin` value alone grants nothing. Only the
   Hall role counts.
6. Portal Hall roles `admin`, `manager` and `scheduler` get no store access
   through SSO.
7. In the store, the person becomes **Hall Manager (`bc_hall_manager`) of that
   Hall only.**
8. A store account that is a **Network Super Admin** is never logged in through
   SSO (BR-034).

## 4. Login flow

The flow always starts at the store, so the store can bind the result to the
exact browser and attempt that started it.

1. The person signs in to the Portal as normal.
2. They click the existing **"Open Ecommerce Store"** button for their Hall.
   The button now points to the store's SSO start address, built from the
   Hall's saved `commerce_site_url`.
3. **Store — start.** The store:
   - refuses the request, creating nothing, if this IP address has already
     started **10 logins in the last minute**, counted from the login attempts
     table itself (each attempt stores a keyed fingerprint of the IP address;
     no separate counter is stored);
   - deletes expired login attempts in one small batch (section 10, rule 4),
     with no reliance on scheduled tasks. An expired attempt is always refused
     as soon as it expires, even if its row has not been deleted yet;
   - finds the Portal Hall ID from its own Hall mapping, never from the link;
   - refuses if the store is not mapped;
   - creates a login attempt (attempt reference, browser secret fingerprint,
     secret proof, SSO reference, this Hall, expiry 5 minutes);
   - sets a temporary private cookie holding the **browser secret** (section
     10). The cookie never holds the attempt reference, and the browser secret
     never appears in any link;
   - redirects the browser to the Portal with the attempt reference, the secret
     proof's fingerprint, the Portal Hall ID and the SSO reference.
4. **Portal — authorize.** The route requires `auth`, `verified` and `role`
   middleware. If the person is not signed in, the Portal's normal login runs
   first and then continues. If that takes longer than the 5-minute attempt
   lifetime, the store later shows the general message and the person clicks
   again. The Portal checks:
   - account active and email verified;
   - `super_admin` on that Hall in the role list, and not a Global Admin;
   - the store is enabled (`HallCommerceService::canOpenStore()`).
5. **Portal — code.** The Portal stores a one-time code (stored only as a
   hash), valid **60 seconds**, usable once, tied to the person, the Hall, the
   attempt reference, the proof fingerprint and the SSO reference.
6. **Portal — return.** The Portal redirects the browser to **that Hall's saved
   store address only** (`commerce_site_url` plus the fixed SSO return path),
   carrying the code and the attempt reference. It never uses a return address
   supplied in the request.

Steps 7 to 12 all happen inside **one request** to the store, in this order:
check the return, exchange the code, account and role, audit, session, then one
final redirect. The code is never put in any other address.

7. **Store — return.** The store checks:
   - a login attempt matching the attempt reference in the link exists, is
     unused, has not expired, and **belongs to this store's Hall** (an attempt
     started at another Hall's store is refused);
   - the browser secret in this browser's private cookie matches the
     fingerprint saved in that attempt, compared with PHP's timing-safe
     `hash_equals`. A browser without that cookie (for example someone who
     copied the return link into another browser) is refused, even if it has
     the code and the attempt reference;
   - then it marks the attempt used with **one conditional update** that
     changes the row only while the attempt is still unused, and continues
     only if exactly one row changed. Two simultaneous returns therefore cannot
     both continue. It deletes the cookie. There is no redirect at this point;
     the same request continues with step 8.
8. **Store — exchange.** The store sends a **POST** request directly to the
   Portal, server to server. The code, the attempt reference and the **secret
   proof** are in the request body, and the SSO token is a bearer token in the
   `Authorization` header. None of them is ever put in the address. The call
   has a fixed **10-second timeout**; no answer means the general message.
9. **Portal — exchange.** The Portal:
   - accepts the SSO token only if it is active, has the SSO purpose and is
     granted this Hall;
   - accepts the code only if it exists, is unexpired, unused, belongs to this
     Hall, matches the attempt reference, and the proof matches the stored
     fingerprint;
   - marks it used in one statement that succeeds only for the first
     request, so two simultaneous exchanges cannot both succeed;
   - **re-checks everything from step 4**: account active and verified,
     `super_admin` on that Hall, not a Global Admin, store enabled;
   - replies with: Portal installation ID, Portal user ID, **Portal user
     creation time** (UTC, fixed format), Portal Hall ID, name, email and the
     SSO reference.
10. **Store — verify answer.** The store checks that:
    - the Portal installation ID equals its configured value;
    - the Portal Hall ID equals the login attempt's Hall, which equals this
      store's mapped Hall.

    From here on the store uses the Hall and SSO reference saved in its own
    login attempt, never the values in the link or in the Portal's answer
    (the answer's values are only compared).
11. **Store — account.** The store finds or creates the account (section 5).
12. **Store — sign in.** The store writes the audit record, creates the login
    session (section 8), and only then makes the **one final redirect**, to
    **this store's admin dashboard**. On any refusal it also makes one
    redirect, to a plain message page whose address carries no code, which
    shows the fixed message. It never follows a return address from the
    request.

The **"Sign in with Portal"** button is shown only on the store's staff login
page (`wp-login.php`), not on the customer My Account login. It starts the same
flow at step 3.

## 5. Store account rules

1. The person is identified by **Portal installation ID + Portal user ID**.
   Email is never used to identify them.
2. A permanent link is stored: "Portal user X (created at time T, planned
   username U, creation operation ID O) = store user Y". One Portal user links to one store user; one
   store user links to one Portal user.
3. **First SSO login**, in this order, all under the existing Hall Staff
   creation lock:
   1. The store checks that no account already uses the person's planned
      Portal-identity username and that no account already uses their Portal
      email (section 6). If either exists, nothing is saved or created.
   2. **The link is saved first**, before any account exists: Portal
      installation ID, Portal user ID, Portal user creation time, the planned
      username, the Hall where creation happens, the **creation operation
      ID** (the operation ID the account-creation audit will use) and this
      login's SSO reference (the **creation SSO reference**), with the store
      user still empty (NULL). The Hall is stored as `creation_blog_id`.
   3. The store creates the account with that **Portal-identity username**,
      never derived from the email, through WordPress's normal Multisite
      creation (`wpmu_create_user()`). During that call WordPress briefly
      writes a role record for the Hall where the request runs (the site's
      default role in `bc_{n}_capabilities`, plus `bc_{n}_user_level`;
      `wp-includes/user.php:2640-2643`) and then **deletes both**
      (`wp-includes/ms-functions.php:1360-1362`). A finished creation therefore
      normally leaves the account with **no role record at all** for any Hall:
      not a role, and not an empty record. WordPress does not check that those
      two deletions worked, so before step 6 the store confirms the account
      has no role record. If only WordPress's temporary records remain on this
      Hall, it removes them with the same narrow cleanup as rule 12; if that
      fails, it stops with the link's store user still empty, and the next
      login finishes through rule 12. SSO never passes an empty role, because
      WordPress would then store an empty role record
      (`wp-includes/class-wp-user.php:628-635`), which the eligibility check
      rejects (`HallStaffEligibility.php:97-116`). SSO uses the Hall Staff
      creation path's lock and duplicate checks only. It **never** uses its
      "assign the existing account that has this email" branch, and sends no
      invitation.
   4. The account gets a random password that is never shown or sent.
   5. Its display name is the person's Portal name. Its public name (the
      author slug) is a random value, so the Portal user number never appears
      in public addresses.
   6. The new store user is written into the link.
   7. The **account-creation audit** is written through the existing mandatory
      creation-audit mechanism (`HallStaffProvisioningService`
      `resolve_creation_witness`). It always uses the values fixed at
      creation: the link's creation operation ID, `creation_blog_id`, planned
      username and creation SSO reference, and the email the account was
      created with, never the current login's values. A retry from a later
      login or another Hall therefore matches the existing record instead of
      conflicting with it. The account's identity type is **`portal_sso`**,
      both in this audit and in the account's `bc_identity_type` marker, never
      `staff_operational`: the existing invitation system sends password-setup
      invitations only to `staff_operational` accounts
      (`HallStaffInvitation.php:95`), so an SSO account can never receive one.
   8. Hall Manager is given on this Hall only, through the existing audited
      Hall Staff path (ADR-025 transport and mandatory audit) and the
      network-wide eligibility check (BR-036). Nothing is granted unless step 7
      succeeded.
4. **Reserved usernames:** no account may be created with a username in the
   Portal-identity format (section 2) except through this SSO creation step. This is enforced in
   **one place that every account creation passes through** (WordPress's
   user-insert step), not route by route. It therefore also covers routes a
   Hall Manager can use, such as WooCommerce's customer API (Hall Managers keep
   WooCommerce's "create customers" permission) and WordPress's user API.
   Without this, someone could create an account with a person's planned
   username before their first login and block their SSO for good.
   The rule applies **only when a new account is created**. Updates to existing
   accounts stay allowed, including SSO accounts and SSO's own naming step:
   WordPress runs its username checks on updates too
   (`wp-includes/user.php:2319`), so an unconditional rule would break them.
5. **Later SSO logins** reuse the linked account.
6. **One person on several Halls:** one store account. Hall Manager is given
   separately on each Hall the first time the person opens that Hall's store.
7. **On each SSO login, these checks run in this order; the first that applies
   decides:**

   | Check | Result |
   |---|---|
   | The linked store account is a Network Super Admin (BR-034) | Refused with the general message; audit entry marked for support review |
   | Link exists but the Portal user creation time differs | Refused with the general message; audit entry marked for support review |
   | Link exists but its store user is still empty (a first login stopped part-way) | Finish that first login from the saved link (rule 12) |
   | Link exists but the linked store account no longer exists, **or its username no longer equals the link's planned username** | Refused with the "contact support" message; audit entry. No replacement account is created |
   | Has Hall Manager on this Hall | Sign in |
   | Account's creation audit is missing (creation was interrupted after the account was written into the link) | Complete the creation audit first (rule 3, step 7); if it cannot be completed, refuse with the general message and grant nothing |
   | Has no role on this Hall | Grant Hall Manager, then sign in |
   | Any other role on this Hall (only a Platform Admin can set one; section 9) | Refused with the access-removed message; role left unchanged |

   **Why the username is compared:** some database versions can give a
   deleted user's number to the next new account after a restart. Without this
   comparison, a link to a deleted account could then point to a different
   person's account. The account is already loaded, so the comparison costs
   nothing extra.

8. **No invitation or welcome email** is sent, because the account has no
   usable password.
9. The store email is copied from the Portal when the account is created. It is
   not updated automatically later. Login is unaffected, because identity is
   the Portal ID.
10. **Simultaneous first logins** produce one account, one link and one grant
    (unique keys on the link plus the existing Hall Staff lock).
11. **A failed attempt grants no access it should not grant; retries never
    create duplicate accounts or grants.** An attempt can stop part-way, for
    example when the database connection drops. Each case is safe:

    | Stopped after | Left behind | Next SSO login |
    |---|---|---|
    | Link saved, account not created | Link with an empty store user, no account | Creates the account from the saved link and continues |
    | Account created, store user not yet written into the link | Link with an empty store user, and an account with the planned username | Finds that account by the link's planned username, writes it into the link and continues |
    | Store user written, creation audit not written | Link and account, no role, no creation audit | Completes the creation audit with the link's creation operation ID (an existing record is checked, not duplicated), then gives the role and signs in. If the audit cannot be completed, nothing is granted |
    | Creation audit written, role not given (grant or its audit failed) | Link, account and creation audit, no role | Gives the role and signs in |
    | Role given, sign-in not finished | Link, account and role | Signs in |

    The person never sees a false "email already used" error after an
    interrupted attempt.
12. **Finishing an interrupted first login.** The store only ever finishes a
    first login recorded in a saved link; it never takes over an account that no
    saved link explains. Under the same lock:
    - if no account has the link's planned username, it creates the account
      (rule 3, steps 3–8). If creation fails because another account has taken
      the email in the meantime, the store deletes the empty link and shows the
      email-conflict message (section 6);
    - if one does, it uses that account only if all of these hold:
      - it was created in the **same second as, or after**, the link was saved.
        Both times are compared in UTC to the second, because WordPress records
        account creation time only to the second (`user_registered`). The
        link's saved time is written by PHP in UTC, the same clock WordPress
        uses for `user_registered`, never by the database's own clock, so a
        clock difference between servers cannot refuse a genuine recovery;
      - it is not linked to anyone else and is not a Network Super Admin;
      - it has **no role record anywhere on the network** (no role, and no
        empty record), apart from the one leftover described next. The role is
        given only after the creation audit, so a genuine interrupted account
        has no other record;
      - **the one allowed leftover:** if creation was interrupted inside
        `wpmu_create_user()` (rule 3, step 3), WordPress may have left its
        temporary records on the link's `creation_blog_id` only: a
        `bc_{n}_capabilities` record that is empty or holds exactly the site's
        default role, and its `bc_{n}_user_level` record. Recovery removes
        **only** those two records, and only for an account that passes every
        other check here. Any other record, on that Hall or any other, is
        unexplained and the account is refused. BR-036 is unchanged: the
        eligibility check runs after this cleanup and must pass;
    - it finishes the naming step (rule 3, step 5) by reusing that step: the
      display name is set to the Portal name, and the public slug is replaced
      by a random value only if it is still the default one built from the
      username. A random slug already set is kept;
    - before any role is given, it completes the creation audit (rule 3, step
      7);
    - otherwise nothing is used or granted, the person sees the general message,
      and an audit entry is marked for support review.

    Because the link (with the Portal user creation time) is saved before the
    account, the Portal restore protection also covers interrupted first logins.
    An account with the planned username but **no saved link** is never used:
    the person sees the general message and an audit entry is marked for
    support review.

## 6. Email already used in the store

WordPress refuses a second account with the same email
(`wp-includes/user.php:2384-2387`). The current Hall Staff creation path
assigns an existing email's account instead of creating one
(`HallStaffProvisioningService.php:95-106`), and customer accounts are not
eligible for Hall roles (BR-035, BR-036). SSO therefore uses this rule:

1. On a first login (no saved link yet), if the Portal email already belongs to
   **any** store account, nothing is saved, created or assigned. Once a link is
   saved, the email is not checked again, so an account left by an interrupted
   first login never causes this error (section 5, rules 11–12).
1a. If the Portal email is longer than WordPress allows (100 characters) or is
    not a valid WordPress email, nothing is saved or created; the person sees
    the general message and an audit entry is marked for support review.
2. The person sees: "Your email is already used in this store. Please ask Bingo
   Innovation support to change the email on your Portal account, then try
   again."
3. **The only supported fix:** a Portal Global Admin changes the person's
   Portal account email to a different valid email the person owns, through the
   Portal's existing user management. Portal users cannot change their own email
   today (the Portal profile only offers a password change). Then the person
   clicks "Open Ecommerce Store" again.
4. No account linking, no account merging, no invented email addresses, no
   database edits and no relaxing of WordPress's duplicate-email rule.
5. An older password-based staff account with that email stays a separate
   account. If it should no longer be used, a Hall Manager or Platform Admin
   removes its role through the normal Hall Staff screen.

## 7. How SSO accounts can and cannot sign in

1. **Allowed:** only through the "Open Ecommerce Store" button or the "Sign in
   with Portal" button.
2. **Refused for SSO accounts:**
   - username and password login (including XML-RPC, which uses the same
     password check);
   - "Lost your password" resets;
   - WordPress application passwords (creating and using them);
   - WooCommerce REST API keys (creating them, and any request authenticated
     with one). Hall Managers hold `manage_woocommerce`, which normally permits
     creating keys (`class-wc-admin-api-keys.php:221`, `class-wc-ajax.php`
     `update_api_key`).
3. **One rule covers every route:** an SSO account is accepted only with a
   store login session created by SSO for the current Hall. Any other way the
   account would be authenticated is refused.
4. **SSO accounts are recognised by their `portal-` username,** not by reading
   the link table or any setting. The refusals above therefore keep working
   when SSO is switched off (section 13) or a table or setting is unavailable.
5. **Existing password-based accounts are unchanged.** This includes the
   platform admin login, current Hall staff and customers. Portal removal does
   not affect those separate accounts.
6. **No cost for anyone else, small cost for SSO accounts:** for every
   non-SSO account these checks stop immediately after looking at the username.
   For SSO accounts the per-request checks use only the login session and the
   `portal-` username, with **no extra database query per request**, except
   saving the last-activity time at most once a minute (section 8, rule 1). The
   link and attempts tables are read only during login.
7. **Profile password fields hidden:** an SSO account's own profile screens
   (the WordPress profile page and the WooCommerce My Account details form) do
   not show "change password" fields. A password would never work for them, so
   showing the fields only confuses people. Hiding them is presentation only;
   the refusals above stay the protection.

## 8. Sessions and logout

1. **Idle limit:** the session ends after **30 minutes without activity**.
   Activity means **any request the person's browser makes, except WordPress
   Heartbeat** (the automatic background check-in) **and any other automatic
   background call a screen makes on a timer without the person doing
   anything** (section 19, check 15). This includes WooCommerce's newer
   screens, which work through background REST and AJAX calls without page
   loads when the person uses them. Automatic calls do **not** count, so an
   untouched admin tab still times out after 30 minutes. If such a call is
   missed, the 1-hour maximum still ends the session.
   The store records the last activity at most once a minute.
2. **Maximum:** the session ends **1 hour** after login, even if active. One
   click on "Sign in with Portal" starts a new session; no password is asked
   while the Portal login is active. Each new session repeats every Portal
   check.
3. **"Remember me" is not offered** for SSO sessions.
4. **One Hall only:** the session records the Hall it was created for. On any
   other Hall site, including the network main site, the SSO account is treated
   as signed out. Store login cookies are shared across all Hall subdomains
   (WordPress sets `COOKIE_DOMAIN` to the network domain in subdomain mode,
   `wp-includes/ms-default-constants.php:84-88`), so this rule must be checked
   on every request, not only at login.
5. **One Hall at a time per browser:** because the login cookie is shared,
   signing in to a second Hall's store in the same browser replaces the first
   login. Returning to the first Hall shows the person as signed out, and one
   click on "Sign in with Portal" switches back. People with several Halls work
   in one Hall at a time.
6. **Expired session in the admin screens:** WordPress normally shows a
   username/password pop-up when a session ends. For SSO accounts it shows "Your
   session has ended. Sign in with Portal to continue." with that button
   instead. Changes not yet saved when the session ends are lost; the record
   accepts this.
7. The limits are enforced on the server, not by trusting the cookie's
   expiry. If the store has no SSO token configured (SSO switched off, section
   13), every SSO session is refused on its next request.
8. **Store logout** ends only the store session. If the Portal is still signed
   in, one click signs back in. This is intended.
9. **Portal logout** does not end the store session. It ends by rule 1 or 2.

## 9. Removing access

1. **Role removed in the Portal, or account deactivated or deleted:** new SSO
   logins are refused immediately. An open store session ends after 30 minutes
   idle or 1 hour at most. **Accepted business risk: up to 1 hour of continued
   access after Portal removal.**
2. **Staff accounts the person created inside the store** (accepted risk).
   Hall Managers manage their Hall's staff (BR-033), so an SSO Hall Manager can
   create normal password-based staff accounts, including Hall Managers, through
   the Hall Staff screen. Those accounts are separate and **survive the person's
   Portal removal**. Procedure: when a Hall Super Admin is removed in the Portal,
   a Platform Admin or another Hall Manager reviews that Hall's Hall Staff list
   and removes any accounts that should no longer have access. Nothing does this
   automatically.
3. **Hall transfer (decided):** when a Hall is transferred in the Portal, the
   new owner gets the `super_admin` role on that Hall and the previous owner
   loses it. SSO then follows the role list: the new owner can open the store,
   and the previous owner gets no new logins (an open session ends by section
   8). The Portal allows one role per person per Hall (`hall_user` is
   unique on Hall and user), so the previous owner's role entry for that
   Hall is removed and they **lose all access to that Hall** in the Portal
   (decided by the user on 2026-10-14); their roles on other Halls are
   unchanged. Today the Portal's `HallManagementService::transferOwnership()`
   (`app/Services/Hall/HallManagementService.php:280-302`) changes only
   `halls.user_id`, so this Portal change is built as part of SSO.
4. **SSO accounts protected in the store.** One rule: **every SSO account
   (username starting with `portal-`) on a Hall is "Managed by Portal".** The
   store does not try to know who the current owner is.
   - The Hall Staff screen shows these accounts as "Managed by Portal", so other
     staff can see they are controlled from the Portal.
   - Hall Managers (employees) have no Add, Remove or Change role action for
     them, on any Hall, and the Hall Staff service refuses **any** role change
     for a `portal-` account (adding, changing or removing) unless it comes from
     the SSO grant itself or a Platform Admin. Hiding the button alone is not
     protection. This also stops a Hall Manager adding someone's SSO account as
     staff on another Hall through "add existing user by email".
   - Only a Platform Admin can remove or change them.
   - This includes a **former owner** whose store role remains after a Hall
     transfer or Portal removal. That account cannot sign in (the Portal refuses
     them), and a Platform Admin removes its role when cleaning up.
   - Employees with normal password accounts are managed exactly as today.
5. **Emergency removal by Bingo Innovation:**
   - a Global Admin removes the person's `super_admin` role in the Portal, so
     no new logins are possible;
   - after waiting two minutes (see the order-and-wait step below), a Platform
     Admin (Network Super Admin) also removes
     the person's Hall Manager role in the store's Hall Staff screen. Only a
     Platform Admin can do this for an SSO account. The person loses
     access to that Hall on their **next request**: WordPress refuses admin
     pages, AJAX and REST requests from someone with no role on that site. Their
     login session itself is not deleted, and other Halls are unaffected;
   - both steps are needed: if the role were removed only in the store while
     the Portal still lists the person as owner, their next click would give
     the role back;
   - **order and wait (procedure):**
     1. Remove the person's Hall `super_admin` role in the Portal.
     2. Wait two minutes.
     3. Remove their Hall Manager role in BingoCommerce.

     Remove Portal access first, wait two minutes, then remove the store role. This wait reduces the chance of an unfinished login restoring access, but does not guarantee cancellation of a delayed login. This is an accepted v1 limitation. No revocation feature is added for this in v1.
6. **Linked store account deleted** (for example by a Network Admin): SSO
   refuses with the "contact support" message and creates no replacement, so a
   deliberate deletion is not undone by the next click. A Platform Admin decides
   how to restore access.
7. The store role is **not** removed automatically when the Portal removes the
   person.

## 10. Security rules

1. HTTPS only in production, for both the Portal and every store.
2. **One-time code:** stored only as a hash, valid 60 seconds, single use, and
   useless without the store's secret proof.
3. **Login attempt:** valid 5 minutes, single use, tied to one store's Hall,
   refused as soon as it expires, and deleted by a later start's cleanup batch
   (rule 4).
4. **Start limits:** at most 10 starts per minute per IP address. Over the
   limit, nothing is created. Expired attempts are deleted on every start, using
   an index on the expiry time and deleting at most a fixed number of rows (for
   example 500) each time, so cleanup work stays small.
5. **Attempt cookie:** holds only the random **browser secret**, never the
   attempt reference or anything else that appears in a link. HTTP-only,
   `SameSite=Lax`, no `Domain` attribute (this store's host only), and lasting
   5 minutes. In production it uses the `__Host-` name prefix, which requires
   `Secure` and `Path=/` and stops any sibling subdomain from setting a
   look-alike cookie. The
   store saves only the secret's fingerprint. The return step is a normal
   top-level redirect, which browsers send `Lax` cookies with. This must be
   tested on the real Portal and store domains.
6. **Logs:**
   - neither system logs the code, browser secret, secret proof or tokens. The
     attempt reference is **not** secret (alone it cannot sign anyone in,
     because the private browser secret is also required), so its appearance in
     ordinary web-server logs of the Portal sign-in link or the store return link
     is acceptable;
   - the return request sends `Referrer-Policy: no-referrer` and always ends
     with one redirect, to the dashboard on success or to a message page with no
     code in its address on refusal (section 4, step 12), so the person never
     stays on the address that carried the code;
   - production web-server logging is configured not to record the query
     string on the store's SSO return path, because that link carries the
     **code** (deployment runbook item). The Portal sign-in link carries no
     code and needs no special log setting.
7. **SSO token:** separate from the snapshot token. It carries an SSO purpose,
   is granted Halls individually, and can be revoked on its own. The snapshot
   endpoint refuses an SSO token and the exchange endpoint refuses a snapshot
   token. It is stored only in server configuration, never in the database,
   Git, logs or pages.
8. The Portal checks the person **twice**: when issuing the code and when
   exchanging it.
9. The Portal redirects only to the Hall's saved `commerce_site_url`.
10. Portal authorize and exchange routes are rate limited.
11. The store's exchange call has a 10-second timeout.
12. Refusals grant nothing. The person sees one of five fixed messages:
    - general: "We couldn't sign you in. Please try again from the Portal.";
    - email conflict (section 6);
    - access removed: "Your access to this store has been removed. Please
      contact Bingo Innovation support.";
    - contact support (linked account deleted): "We couldn't find your store
      account. Please contact Bingo Innovation support.";
    - store disabled: the existing suspension page.

    The Portal shows its normal "no access" page when its own checks fail.
13. **Audit:** each system records what it knows. One **SSO reference** is
    created at login start and stored in the details of every related audit
    record, on both systems, to join them. Each audit record keeps **its own**
    operation ID, because the store's audit table requires a unique operation ID
    per record (`bingocommerce-core/src/Migrations.php:281-294`); a retried
    operation reuses its own operation ID.
    - **Store auditing begins at the exchange step.** Starts that never return
      from the Portal, start-limit refusals and return-step refusals are not
      audited, so junk requests cannot fill the audit log.
    - **Portal:** person, Hall, code issued, code exchanged, refusal reason,
      SSO reference.
    - **Store:** Portal installation ID, Portal user ID, store user ID, Hall,
      Portal Hall ID, result, SSO reference.
    - Account creation and Hall Manager grants are audited through the existing
      mandatory Hall Staff audit, with `source = portal_sso` and no human actor
      (`actor_user_id = null`, which `AuditLog` supports for system events).
    - Creation-time mismatches, deleted linked accounts, accounts no saved link
      explains, and interrupted logins that cannot be finished create an audit
      entry marked for support review.
    - Never recorded: codes, proofs, browser secrets, tokens or passwords.
    - If the store's login audit cannot be written, the person is not signed in.
14. SSO pages are never cached.
15. **No MFA in v1** (section 14). The Portal password alone gives the
    person's **Hall Manager permissions** in the store, including payments and
    refunds.

## 11. Store disabled by the Global Admin

1. Customer and staff access is blocked by the **existing suspension rules**
   (`HallCommerceGate`), including staff already signed in.
2. Its existing exceptions stay unchanged: approved payment confirmations,
   required scheduled processing and Platform Admin recovery. SSO adds no new
   exception. The gate allows only the `''`, `login` and `logout` actions of
   `wp-login.php` (`HallCommerceGate.php:125`), so the SSO actions are refused
   while the store is disabled.
3. The Portal does not offer the button while `canOpenStore()` is false, and
   the exchange re-checks it.
4. After re-enabling, an unexpired session continues; otherwise the person
   clicks the button again.

## 12. Failure cases

| Case | Result |
|---|---|
| More than 10 starts per minute from one IP address | Refused; nothing created or audited |
| Login attempt expired (for example a slow Portal sign-in) | Refused with the general message |
| Login attempt started at Hall A's store, returned to Hall B's store | Refused |
| Code expired, already used, or exchanged twice at once | Refused; only one exchange can succeed |
| Return link opened in a browser without the private browser-secret cookie (for example a copied link), or the cookie does not match the attempt | Refused |
| Code exchanged without the store's secret proof | Refused |
| Code issued for Hall A, returned to Hall B | Refused |
| Role lost, account deactivated or email unverified between click and exchange | Refused |
| Store disabled at any step | Refused |
| SSO token missing, revoked, wrong purpose, or not granted for this Hall | Refused |
| Portal installation ID or Portal Hall ID in the answer does not match | Refused |
| Portal unreachable or no answer within 10 seconds | Refused with the general message; existing sessions unaffected |
| Portal user creation time differs from the linked value | Refused; audit entry marked for support review |
| Linked store account no longer exists | Refused with the "contact support" message; no replacement created |
| Email already used in the store | Refused with the email-conflict message |
| A role other than Hall Manager on this Hall | Refused with the access-removed message |
| A Hall Manager (employee) tries to add, remove or change a role for an SSO account ("Managed by Portal"), on any Hall | Refused; nothing changes |
| Store account is a Network Super Admin, or fails BR-036 eligibility | Refused |
| Linked account's username no longer equals the link's planned username (its user number was reused) | Treated as a deleted linked account: "contact support"; no replacement created |
| Account with a `portal-` username created through any route other than the SSO creation step (including WooCommerce's customer API) | Refused at creation; nothing is created |
| First login stopped part-way (link saved; account or its link entry not finished) | No access; the next login finishes it from the saved link; no email error |
| An account with the planned username exists but no saved link explains it, or it fails the finishing checks | Refused with the general message; audit entry marked for support review |
| Another account takes the email between saving the link and creating the account | Empty link deleted; email-conflict message |
| Account created but its creation audit was not written | No access; the next login completes the creation audit first, then grants |
| Account created but grant or its audit failed | No access; next attempt reuses the account |
| Interrupted inside account creation, or WordPress's own clean-up silently failed, leaving its temporary role record (empty or the site's default role) and its user-level record on `creation_blog_id` | Removed only those two records (straight away, or by recovery on the next login), then the eligibility check must pass; any other record refuses the account |
| Portal email longer than 100 characters or invalid for WordPress | Refused with the general message; audit entry marked for support review |
| SSO switched off (no SSO token in the store's configuration) | New logins refused with the general message; open SSO sessions refused on their next request; password ban still applies |
| Link table or another SSO table unreadable | New logins refused with the general message; password ban still applies, because it uses the `portal-` username |
| Store login audit cannot be written | Not signed in |

## 13. Rollout

1. **Halls already enabled.** The SSO token is normally granted to a Hall when
   the Global Admin enables it. Halls enabled before SSO exists (in
   development: 868, 869 and 870) never went through that step. A one-time,
   audited step grants the SSO token to every Hall that is already enabled.
2. **Existing password accounts with the same email.** Before SSO is switched
   on, list every Hall Super Admin whose Portal email is already used by a
   store account. For each, a Global Admin changes their Portal email before the
   person's first SSO login. (Removing the old account's Hall role does not free
   the email, because the account still owns it; removing old staff access is a
   separate action.)
3. **Reserved usernames.** Before SSO is switched on, confirm that no existing
   store account has a username starting with `portal-`.
3a. **Former owners from earlier transfers.** Hall transfers made before the
    Phase 3b change only updated the Hall's owner field and left the previous
    owner's `super_admin` role in place. Before SSO is switched on in an
    environment, list every Hall `super_admin` entry held by someone other than
    the Hall's current owner (read-only). Bingo Innovation reviews each one;
    removing an entry is a deliberate Portal action, never automatic. Also
    list Halls whose current owner has **no** `super_admin` entry: the Portal
    still lets such an owner in through the owner field, but SSO follows the
    role list only, so that owner cannot use SSO until a Global Admin gives
    them the entry. (Checked on development on 2026-10-14: none of either
    kind found, and no transfers recorded.)
4. **Configuration per environment:** Portal installation ID and SSO token
   (store); SSO token ID, token purpose and installation ID (Portal).
5. **Production web-server logging** for the SSO return path is configured
   before SSO is enabled (section 10, rule 6).
6. **Turning SSO off and on:**
   - **Off (full):** remove the SSO token from the store's server
     configuration. New SSO logins stop, and open SSO sessions are refused on
     their next request.
   - **Off (new logins only):** revoke the SSO token in the Portal. Open
     sessions end by the normal 30-minute and 1-hour limits.
   - **On:** put the token back (or issue and configure a new one).
   - Only Bingo Innovation does this (a Global Admin in the Portal, or its
     technical staff on the server). Hall staff never see it.
   - **SSO is switched off, never uninstalled.** Removing the SSO code would also
     remove the password ban, and the SSO accounts could then set passwords
     that the Portal does not control.

## 14. Changes to existing decisions (in effect since approval on 2026-10-13)

1. **ADR-012:** an exception for SSO-linked Hall Manager accounts in v1 only.
   MFA remains required for every other account it covers. The added risk is
   recorded in section 10, rule 15.
2. **BR-075:** this ADR supplies the deferred SSO protocol, claims, expiry,
   replay defence, account mapping and failure behaviour. SSO does not satisfy
   ADR-012; the v1 exception is the one recorded here.
3. **ADR-035 and ADR-042 point 11:** Portal users are still not synchronized in
   bulk. The only change: a Hall Super Admin's own store account is created on
   their first SSO login. Existing Hall staff invitation stays unchanged.
4. **ADR-025 and BR-033:** add one narrow Hall Staff entry used only by SSO. It
   may grant only `bc_hall_manager`, only after a confirmed Portal answer, only
   to the linked account, only on the current Hall, only when that account has
   no role there, only after the account's creation audit is complete, and only
   if BR-036 eligibility passes and
   the account is not a Network Super Admin. It uses the same transport, lock
   and mandatory audit. Every existing Hall Staff path stays unchanged,
   including Hall Managers' right to create staff (section 9, rule 2).
5. **BR-035:** a new identity type, "Portal SSO staff identity, no password"
   (`portal_sso`).
   §3 gains one narrow exception: Hall Managers cannot add, change or remove a
   role for an SSO account ("Managed by Portal") on any Hall; only the SSO grant
   and a Platform Admin can.
   BR-033's Hall Manager staff authority is otherwise unchanged. §4 Platform
   Admin lifecycle controls are unchanged (still not implemented).
6. **BR-034 and BR-036:** unchanged and applied.
7. **AGENTS.md (this repository):** §2 and §4 are updated when SSO is
   implemented. Rule 5 is unaffected (no new BingoCommerce REST route; the store
   steps are `wp-login.php` actions). Rule 4 is unaffected (no new direct mysqli
   transport; new tables use `$wpdb->prepare()`; grants use the existing ADR-025
   transport).
8. **Portal `AGENTS.md`, `README.md` and `docs/BINGOCOMMERCE_INTEGRATION.md`**
   are updated when SSO is implemented. The Portal's Ecommerce section stays
   read-only for Hall Super Admins; only the button's destination changes.

## 15. Data stored

**BingoCommerce** (owned by `bingocommerce-portal-integration`, created by its
versioned migration with `dbDelta()`):

1. **SSO links** (network table): Portal installation ID, Portal user ID,
   Portal user creation time (UTC), planned username, `creation_blog_id`
   (indexed), creation operation ID, creation SSO reference, store user ID
   (nullable: NULL until the account is created, never 0), created time.
   Unique on (installation ID, Portal user ID), on planned username and on
   store user ID. MySQL allows any number of NULL values in a unique index, so
   pending links never block each other. Saved before the account is created
   (section 5, rule 3). A row describes a **person**, not a Hall membership.
   `creation_blog_id` only records which Hall's store the account was first
   created from, for recovery (section 5, rule 12) and audit attribution. It
   gives **no** access to any Hall: access comes only from the store's normal
   per-site Hall roles.
2. **Login attempts** (network table, tenant-attributed): attempt reference
   hash (unique), browser secret fingerprint, secret proof, IP address
   fingerprint (a keyed hash made with `hash_hmac` and a server secret, so a
   copy of the database alone cannot reveal addresses; indexed with the
   created time, for the start limit), `blog_id`
   (indexed), SSO reference, created,
   expiry (indexed) and used times. Deleted in small batches on later starts
   after expiry (section 10, rule 4).
3. **Login session details** are stored with WordPress's own session record:
   Hall, login time and last activity.
4. **Configuration constants:** the expected Portal installation ID and the SSO
   token. They are never stored in the database.

**Portal:**

1. **One-time codes table:** code hash (unique), user ID, Hall ID, attempt
   reference hash, proof fingerprint, SSO reference, expiry (indexed), used time
   and created time. Expired rows are deleted in small batches whenever a new
   code is issued, so no scheduled task (cron) is needed.
2. **Integration tokens:** a purpose value is added. Existing tokens keep the
   snapshot purpose. The SSO token is configured separately (for example
   `services.bingocommerce.sso_token_id`) and is granted each Hall at the same
   point the snapshot token is granted during Enable Ecommerce
   (`app/Services/Hall/HallCommerceService.php:105-114`), plus the one-time
   grant for already-enabled Halls (section 13).
3. **Installation ID** in configuration.

The Portal schema changes need a migration with rollback notes (Portal AGENTS
review guidelines).

## 16. Not included in v1

1. MFA.
2. Global Admin SSO.
3. SSO for Portal `admin`, `manager` and `scheduler` roles, and for Game
   Operators.
4. Immediate logout on Portal removal (up to 1 hour is accepted).
5. Automatic removal of the store role when the Portal removes a person.
6. Automatic removal of staff accounts the person created inside the store.
7. Signing in to two Halls at the same time in one browser.
8. Logging out of both systems together.
9. Updating the store email when the Portal email changes.
10. Linking or converting existing password-based store accounts.
11. Automatically re-creating a deleted linked store account.
12. Hall Managers removing or changing an SSO account ("Managed by Portal").

## 17. What gets built

**Portal**

1. One-time codes table; expired codes deleted in small batches when new codes
   are issued (no cron).
2. Token purpose; SSO token configuration and per-Hall grant at Enable.
3. Hall transfer moves the `super_admin` role from the previous owner to the
   new owner (section 9, rule 3), with an audit entry.
4. One-time audited grant of the SSO token to already-enabled Halls.
5. The Hall Super Admin's existing **"Open Ecommerce Store"** button points to
   the store's SSO start address. The note "Separate WordPress login is
   required… SSO is not implemented." is removed. The Global Admin's "Open
   Store" button is unchanged.
6. Authorize route (`auth`, `verified`, `role`, rate limited): checks the person
   and issues the code.
7. Exchange endpoint (SSO token, Hall grant, rate limited): consumes the code,
   re-checks everything and returns the fixed answer, including the user
   creation time.
8. Audit entries.
9. Feature tests.

**BingoCommerce**

1. Migration for the links and login attempts tables.
2. The two `wp-login.php` actions (start and return): start limit per IP
   address, expired-attempt cleanup, attempt-to-store check, and the "Sign in
   with Portal" button on the staff login page only.
3. Server-to-server exchange through the existing Portal HTTP client pattern
   (no redirects, no cookies, fixed error categories, 10-second timeout), sent
   as a POST with the values in the body and the token in the header.
4. Account creation and Hall Manager grant through the Hall Staff path, with
   the narrow SSO entry from section 14.4: link saved before the account is
   created, Portal-identity username, random public name, reserved `portal-`
   pattern enforced at WordPress's user-insert step, finishing an interrupted
   first login only from its saved link (section 5, rule 12), creation-time
   check, username-equals-link check and deleted-account refusal.
5. Refusal of password, reset, application passwords, WooCommerce API keys and
   XML-RPC for SSO accounts, recognised by their `portal-` username.
6. Session rules: one Hall only, 30-minute idle counting every request except
   WordPress Heartbeat and other automatic background calls (section 8,
   rule 1), 1-hour maximum, SSO sessions refused when no SSO token is configured, and the
   "Sign in with Portal" message in WordPress's expired-session pop-up; password
   fields hidden on the SSO account's own profile screens.
7. "Managed by Portal" label for every SSO account in the Hall Staff screen, no
   Add, Remove or Change role action for Hall Managers on those accounts, and
   the same refusal in the Hall Staff service for any role change on a `portal-`
   account not made by the SSO grant or a Platform Admin.
8. Audit entries.
9. Test harness in `tests/`.

## 18. Tests

1. First SSO login creates the account; a second login reuses it.
2. Refused: Global Admin (including one holding `super_admin` on the Hall),
   `admin`, `manager`, `scheduler`, another Hall's Super Admin, inactive or
   unverified accounts.
3. Code expired; used twice at the same moment; used without the proof;
   returned to another Hall. Two simultaneous returns with the same attempt
   and cookie: only one continues to the exchange.
3a. **Copied return link:** start a login in browser A, capture the return link
    (code and attempt reference) before browser A uses it, and open it in browser
    B, including with a cookie set by hand to the attempt reference. Browser B is
    refused and nothing is created. Browser A, which holds the real browser
    secret, can still complete if the code is unused and unexpired.
4. Role lost, account deactivated or store disabled between click and exchange.
5. A login made through Hall B is treated as signed out on Hall A and on the
   main site.
6. Portal removal:
   - (a) a **new** Hall A login is refused;
   - (b) an **existing** Hall A session continues only until 30 minutes idle or
     1 hour;
   - (c) Hall B keeps working.
7. Two first logins at the same moment create one account, one link and one
   grant.
8. Email already used by a customer account; by an older staff account.
9. Password login, password reset, application password, WooCommerce API key
   (creation and use) and XML-RPC are refused for SSO accounts; existing
   password accounts are unaffected.
10. 30-minute idle logout and 1-hour logout, enforced on the server. Every
    automatic background call found in check 15 is left running on an
    untouched tab, and the tab is still signed out after 30 minutes. An admin
    tab left untouched for more than 30 minutes (with WordPress Heartbeat still
    running) is signed out; a person working for 35 minutes only in WooCommerce
    screens that use REST/AJAX calls without page loads is **not** signed out
    before the 1-hour maximum.
11. Store disabled: signed-in staff are blocked, the SSO actions are refused, and
    payment confirmations still work.
12. **SSO account protection:** the Hall Staff screen shows "Managed by Portal"
    for every SSO account, including a former owner's; a Hall Manager sees no
    Add, Remove or Change role action for them and a direct attempt is refused;
    a Hall Manager of another Hall cannot add an SSO account as staff by its
    email; a Platform Admin can remove them; employees are still managed
    normally.
13. Portal unreachable; no answer within 10 seconds; SSO token revoked, wrong
    purpose, or not granted for the Hall; snapshot token refused at the exchange
    endpoint.
14. Wrong Portal installation ID or Portal Hall ID in the answer.
15. Grant audit failure: no access, then a successful retry reuses the account.
    Store login audit failure: not signed in.
16. **Interrupted first login:** stop the process (a) after the link is saved
    and before the account is created, and (b) after the account is created and
    before it is written into the link. In both cases the next SSO login
    succeeds, shows no "email already used" error, creates no second account and
    leaves exactly one link. In case (b) the account ends with the Portal name
    and a random public slug. Repeat with two simultaneous retries: one
    account, one link, one grant. Two different people with pending links at
    the same time do not block each other.
17. **Unexplained account refused:** an account with the person's planned
    username but no saved link, or one that fails the finishing checks (created
    before the link, linked elsewhere, Network Super Admin, or any role record
    other than WordPress's temporary creation records in test 17b), is never
    used; the person sees the general message and an audit entry is
    marked for support review.
    If another account takes the email between saving the link and creating the
    account, the empty link is deleted and the email-conflict message is shown.
17a. **Creation audit on recovery:** make the account-creation audit fail, then
     retry SSO. Result: one account, one completed creation audit (with the
     link's creation operation ID) and one role grant; no grant happens while
     the creation audit is missing. Repeat the retry from a different login
     and from another Hall: it matches the existing creation audit (no
     conflict). The account is marked `portal_sso` and cannot be sent a Hall
     Staff invitation.
17b. **Interrupted inside account creation:** stop the process right after
     WordPress writes its temporary role and user-level records and before it
     deletes them, then retry. Repeat with an empty role record. Result: recovery
     removes only those records on `creation_blog_id`, the eligibility check
     passes, and there is one account with exactly one Hall Manager role. A
     different leftover record (another role, or any record on another Hall)
     makes recovery refuse the account. Also make WordPress's own deletion
     fail silently during a normal creation: the store removes the leftover
     before writing the account into the link, or stops and the next login
     finishes it; the person is never left with "access removed".
17c. **Same-second times:** recovery succeeds when the link and the account
     have the same creation second.
17d. **Long email:** a Portal email over 100 characters is refused with the
     general message and nothing is created.
17e. **No extra queries:** a normal request by a non-SSO account makes no
     additional database query for the SSO checks; an SSO account's request
     makes none either, apart from the last-activity save at most once a
     minute.
17f. **Same clock:** the link's saved time and the account's `user_registered`
     are both written by PHP in UTC; recovery succeeds when the database
     server's clock differs from the PHP server's.
18. **Reserved username:** creating a `portal-` username through WordPress
    registration, Network Admin "Add user", the site "Add user" screen,
    WordPress's user API, WooCommerce's customer API (as a Hall Manager),
    WooCommerce My Account or WooCommerce checkout is refused; only the SSO
    creation step succeeds. A customer or Hall Staff member whose automatic
    username is generated from an email such as `portal-mike@gmail.com` is
    created normally. Updating an existing SSO account (profile save,
    SSO's naming step, WooCommerce customer update) still succeeds.
19. **Attempt from another store:** an attempt started at Hall A's store and
    returned to Hall B's store is refused.
20. **Start flooding:** the 11th start in a minute from one IP address is refused
    and creates nothing; expired attempts are deleted on later starts, no more
    than the fixed batch each time, using the expiry index; no audit entries are
    written for starts that never reach the exchange.
21. **Expired session pop-up:** shows "Sign in with Portal", never a password
    form, for an SSO account.
21a. **Profile screens:** an SSO account's WordPress profile page and WooCommerce
     My Account details form show no password fields; other accounts still
     see them.
22. **Two Halls in one browser:** signing in to Hall B makes the Hall A tab
    show signed out; one click on "Sign in with Portal" switches back.
23. **Linked account deleted:** refused with "contact support"; no new account
    is created.
23a. **Reused user number:** delete the linked account, then make a new,
     unrelated account receive the same user number. The SSO login is refused
     with "contact support" and never signs in to the new account.
23b. **Network Super Admin:** make the linked SSO account a Network Super Admin;
     the next SSO login is refused, even though it holds Hall Manager.
24. **Portal restore:** a link whose stored creation time differs from the
    Portal's answer is refused, with an audit entry marked for support review.
25. **Public name:** the account's public author address does not contain the
    Portal user number.
26. **Already-enabled Halls:** after the one-time grant, SSO works on a Hall
    enabled before SSO existed.
27. **Hall transfer (Portal):** after a transfer, the new owner holds
    `super_admin` and can open the store; the previous owner no longer holds it
    and a new login is refused.
28. **Emergency removal:** after the Portal role and the store role are both
    removed, the person's next request to that Hall is refused on admin pages,
    AJAX and REST; other Halls are unaffected; and the next click does not give
    the role back. If only the store role is removed, the test documents that the
    next click restores it (the reason both steps are required). **Delayed
    login:** a login whose code was exchanged just before the Portal removal is
    allowed to finish late (for example, held up after the exchange), after the
    store role was removed. The test records what happens, so the accepted v1
    limitation is understood; it is not expected to pass as "access cannot
    return".
29. **SSO switched off:** with no SSO token in the store's configuration, new
    SSO logins are refused, an open SSO session is refused on its next request,
    and password login and password reset are still refused for SSO accounts.
    Putting the token back restores SSO with the same accounts.
30. **SSO table unreadable:** new SSO logins are refused with the general
    message; password login and reset are still refused for `portal-` accounts.
31. The attempt cookie works on the real Portal and store domains.
32. Neither system's logs or audit records contain the code, browser secret,
    proof or tokens. Each audit record has its own operation ID; related
    records share the SSO reference.
33. Existing Hall Staff, provisioning, suspension (Hall Commerce access), Portal
    sync and Portal test suites still pass.

## 19. Checks to confirm during implementation

These are facts the build must verify before relying on them:

1. The Portal user-management edit flow lets a Global Admin change a user's
   email, and whether it resets email verification.
2. The exact WordPress 7.0.3 and WooCommerce 11.1.2 points used to refuse
   password, reset, application-password, API-key and XML-RPC authentication
   for SSO accounts.
3. The ADR-025 Hall Staff transport can accept the narrow system grant without
   weakening any existing check. This is reviewed before other work.
4. Attempt-cookie behaviour on the real production domains.
5. Production web-server log configuration for the SSO return path.
6. The username used by WordPress's account-creation function is written in
   the same database insert as the account, and the `portal-` pattern can be
   reserved at WordPress's user-insert step for new accounts only, which every
   registration route passes through (WordPress registration, Network and site
   "Add user", the WordPress user API, WooCommerce's customer API, My Account
   and checkout account creation), while still allowing the SSO creation step.
7. How the start limit identifies the visitor's IP address behind the chosen
   production proxy or CDN. Only a trusted proxy's forwarded address may be
   used; otherwise all visitors would share one limit, or the limit could be
   bypassed.
8. The WordPress expired-session pop-up (`wp-auth-check`) can show the "Sign in
   with Portal" message for SSO accounts without changing it for anyone else.
9. The Portal's `users.created_at` value is never changed after creation.
10. The Hall Staff service's add, remove and role-change paths are the only
    store paths that change a Hall role, so refusing role changes on `portal-`
    accounts there protects SSO accounts everywhere (the existing writer guard
    blocks other paths).
11. The Portal transfer change (section 9, rule 3) fits the Portal's existing
    hall-role rules (Portal `AGENTS.md` §2.1) and its user-management flows.
12. How the store reliably recognises WordPress Heartbeat requests (the
    `heartbeat` AJAX action). Heartbeat and the automatic calls found in check
    15 are the only requests excluded from the idle limit.
13. On the installed WordPress 7.0.3 Multisite setup, confirm exactly which
    records `wpmu_create_user()` writes and then deletes (expected:
    `bc_{n}_capabilities` and `bc_{n}_user_level` for the Hall where the request
    runs), and that a finished creation leaves no role record. Also confirm no
    plugin hook on `user_register` or `wpmu_new_user` (for example WooCommerce)
    adds a role for the new account.
14. That `resolve_creation_witness` (or an equivalent existing method) can be
    reused for the SSO creation audit with the link's creation operation ID,
    without weakening it.
15. Which automatic background calls the store's admin screens make on a timer
    (WordPress, WooCommerce and BingoCommerce screens), and how each is
    recognised, so none of them counts as activity.
16. The WordPress and WooCommerce points for hiding password fields on an SSO
    account's own profile screens.

## 20. Open items

None. Both earlier open items are decided: a Hall transfer moves the
`super_admin` role to the new owner (section 9, rule 3), and the store "block"
is replaced by protecting every SSO account as "Managed by Portal" (section 9,
rule 4), so no re-allow rule is needed.

## 21. Order of work

1. This record (docs only).
2. User approval (given 2026-10-13).
3. Portal side and store side built; the Hall Staff system grant is reviewed
   first.
4. Local end-to-end test with two Halls, including the cross-Hall cases.
5. Security review before merging (CLAUDE.md).
6. Rollout steps (section 13) before SSO is enabled in any environment.
