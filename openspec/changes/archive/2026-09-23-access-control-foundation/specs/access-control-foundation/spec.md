# Access Control Foundation Specification

## Purpose

Define user identity, credential verification, server-side session lifecycle, account lockout, role authorization guards, post-login redirection, and administrative CLI tooling across Ferreterías El Constructor.

## ADDED Requirements

### Requirement: User Account Identity and Role Persistence

The system SHALL persist application user accounts identified by a unique `username`. Each account SHALL be associated with exactly one supported operational role (`administrador`, `cajero`, `bodeguero`, `compras`) and an explicit lifecycle state (`creado`, `activo`, `bloqueado`, `inactivo`). Generic account creation SHALL result in state `creado` unless an explicitly authorized provisioning operation deliberately activates the account. The administrative create-user CLI SHALL provision its account as `activo`. The system SHALL persist passwords only as secure hashes and SHALL NOT store passwords in plaintext or reversible formats.

#### Scenario: Persist account with supported role and state
- GIVEN valid account details with username, supported role, and state
- WHEN the account is created
- THEN the system MUST store the account with its assigned role and state
- AND the password MUST be stored as a secure hash

#### Scenario: Reject duplicate username
- GIVEN an existing account with a given username
- WHEN another account creation is attempted using the same username
- THEN the system MUST reject the creation with a uniqueness validation error
- AND no duplicate record MUST be stored

#### Scenario: Created account cannot authenticate
- GIVEN an account in state creado
- WHEN the user attempts to log in with valid credentials
- THEN the system MUST deny authentication
- AND no authenticated session MUST be established

#### Scenario: Inactive account cannot authenticate
- GIVEN an account in state inactivo
- WHEN the user attempts to log in with valid credentials
- THEN the system MUST deny authentication
- AND no authenticated session MUST be established

---

### Requirement: Username Credential Authentication

The system SHALL authenticate users using `username` as the sole login identifier. The system SHALL NOT use email as an authentication identifier. Valid credentials for an account in `activo` state SHALL authenticate successfully. Unrecognized usernames, incorrect passwords, and non-active accounts (`creado`, `bloqueado`, `inactivo`) SHALL be denied. Public authentication failure responses SHALL provide generic denial messaging and SHALL NOT reveal whether the username exists, whether the password was incorrect, or what state the account holds.

#### Scenario: Authenticate active user with valid credentials
- GIVEN an existing account in state activo
- WHEN the user submits the correct username and matching password
- THEN the system MUST authenticate the user
- AND the system MUST establish an authenticated session

#### Scenario: Reject authentication with incorrect password
- GIVEN an existing account in state activo
- WHEN the user submits the correct username with an incorrect password
- THEN the system MUST deny authentication
- AND the response MUST provide generic failure messaging without disclosing password correctness

#### Scenario: Reject authentication for nonexistent username
- GIVEN a username that does not exist in the system
- WHEN a login attempt is submitted with that username
- THEN the system MUST deny authentication
- AND the response MUST provide the same generic failure messaging without disclosing account existence

#### Scenario: Reject authentication for blocked account
- GIVEN an existing account in state bloqueado
- WHEN a login attempt is submitted with valid credentials
- THEN the system MUST deny authentication
- AND the response MUST provide generic failure messaging without disclosing that the account is blocked

---

### Requirement: Password Contract and Hashing Standards

The system SHALL enforce a canonical password policy requiring a minimum length of 15 Unicode characters and a maximum encoded length of no greater than 72 UTF-8 bytes. If a submitted password exceeds 72 UTF-8 bytes, the system MUST reject validation with an error and MUST NOT silently truncate the password or pass a truncated prefix to the hashing algorithm. The system SHALL NOT enforce arbitrary composition requirements (mandatory uppercase, lowercase, numbers, or symbols) and SHALL NOT require periodic password rotation. The system SHALL hash persisted passwords using bcrypt with cost 10.

#### Scenario: Accept passphrase meeting length and byte constraints
- GIVEN a password with at least 15 Unicode characters whose UTF-8 byte length is less than or equal to 72 bytes
- WHEN the password is submitted during account provisioning
- THEN the system MUST accept the password
- AND the system MUST compute a bcrypt hash using cost 10

#### Scenario: Reject password shorter than 15 Unicode characters
- GIVEN a password with fewer than 15 Unicode characters
- WHEN the password is submitted during account provisioning
- THEN the system MUST reject the password with a length validation error
- AND no account or hash MUST be stored

#### Scenario: Reject password exceeding 72 UTF-8 bytes without truncation
- GIVEN a password whose UTF-8 encoded representation exceeds 72 bytes
- WHEN the password is submitted
- THEN the system MUST reject the submission with an error
- AND the system MUST NOT silently truncate the password to 72 bytes
- AND no truncated prefix MUST be hashed

#### Scenario: Multibyte characters evaluated by codepoints for minimum and bytes for maximum
- GIVEN a passphrase composed of multibyte Unicode characters
- WHEN length validation runs
- THEN the minimum check MUST count Unicode characters rather than raw bytes
- AND the maximum check MUST enforce that the encoded UTF-8 byte sequence does not exceed 72 bytes

---

### Requirement: First-Failure Fixed Window Account Lockout

The system SHALL automatically transition an `activo` account to `bloqueado` when more than 5 failed password attempts occur within a 10-minute window starting from the first failure. The first failed attempt SHALL initialize a 10-minute counting window and set the failure count to 1. Failures 1 through 5 within the active window SHALL NOT block the account. The 6th failure occurring within that same 10-minute window SHALL immediately change the account state to `bloqueado`. If the 10-minute window expires without reaching the 6th failure, the subsequent failure SHALL discard the expired window and start a new 10-minute window with count 1. Successful authentication of an `activo` account SHALL reset failure counters and window metadata. A blocked account SHALL remain blocked and SHALL NOT be unlocked by submitting valid credentials.

#### Scenario: First failed attempt initializes fixed window
- GIVEN an active account with zero recorded failures
- WHEN an invalid password is submitted
- THEN the system MUST record the failure count as 1
- AND the system MUST record the start of the 10-minute failure window
- AND the account state MUST remain activo

#### Scenario: Five failed attempts within window keep account active
- GIVEN an active account with 4 recorded failures within the current 10-minute window
- WHEN a 5th invalid password is submitted within the window
- THEN the failure count MUST increment to 5
- AND the account state MUST remain activo

#### Scenario: Sixth failed attempt within window locks account
- GIVEN an active account with 5 recorded failures within the current 10-minute window
- WHEN a 6th invalid password is submitted before the 10-minute window expires
- THEN the failure count MUST increment to 6
- AND the account state MUST transition immediately to bloqueado
- AND subsequent authentication attempts MUST be denied

#### Scenario: Window expiration before sixth failure resets counter
- GIVEN an active account with 5 recorded failures whose 10-minute window has elapsed
- WHEN an invalid password is submitted after window expiration
- THEN the previous window MUST be discarded
- AND a new 10-minute failure window MUST be initialized with failure count 1
- AND the account state MUST remain activo

#### Scenario: Successful login resets failure metadata
- GIVEN an active account with between 1 and 5 recorded failures
- WHEN the user successfully authenticates with the correct password
- THEN the failure count MUST be reset to 0
- AND the failure window metadata MUST be cleared
- AND the account MUST remain activo

#### Scenario: Correct password does not unlock blocked account
- GIVEN an account in state bloqueado
- WHEN the user submits the correct password matching the account's hash
- THEN the system MUST deny authentication
- AND the account state MUST remain bloqueado

---

### Requirement: Lockout Concurrency Invariant

Concurrent failed authentication attempts SHALL be counted without lost increments and SHALL NOT allow a qualifying sixth failure to bypass the lockout rule. When simultaneous invalid attempts occur for an active account, the system MUST ensure reliable counter tracking such that a qualifying 6th failure reliably transitions the account to `bloqueado`.

#### Scenario: Simultaneous failed attempts do not lose increments
- GIVEN an active account with 4 recorded failures in the active window
- WHEN two failed authentication requests execute concurrently
- THEN both failures MUST be accounted for
- AND the account state MUST transition to bloqueado upon reaching the 6th failure

---

### Requirement: Administrative User Bootstrap CLI

The system SHALL provide an administrative command-line interface (`create-user`) for controlled initial account provisioning. The command SHALL accept an explicit username, an explicit supported role, and obtain the password interactively without echoing plaintext to the terminal. The command SHALL validate the password against the canonical password contract ($\ge 15$ characters, $\le 72$ bytes), hash it with bcrypt at cost 10, reject duplicate usernames, and create the account with initial state `activo`. If secure no-echo terminal input cannot be guaranteed on the running platform, the command SHALL fail closed with an error and SHALL NOT silently fall back to echoed input. The system SHALL NOT generate default administrator accounts automatically during database migrations or seeding.

#### Scenario: Provision active administrator via CLI
- GIVEN a unique username and role administrador
- WHEN an operator executes create-user and provides an interactive valid passphrase
- THEN the system MUST create the user account with state activo
- AND the password MUST be hashed with bcrypt at cost 10
- AND the operator MUST be able to immediately authenticate with the created credentials

#### Scenario: Reject CLI user creation with duplicate username
- GIVEN an existing username in the database
- WHEN create-user is executed with the existing username
- THEN the command MUST fail with an error
- AND no duplicate record MUST be inserted

#### Scenario: Reject CLI user creation violating password policy
- GIVEN a password with fewer than 15 characters or exceeding 72 UTF-8 bytes
- WHEN create-user is executed
- THEN the command MUST reject the password with a validation error
- AND no account record MUST be created

#### Scenario: Fail closed when secure input is unavailable
- GIVEN an environment where terminal echo cannot be disabled or captured securely
- WHEN create-user attempts to prompt for a password
- THEN the command MUST terminate with an error indicating secure input is unavailable
- AND the command MUST NOT echo the password in plaintext

---

### Requirement: Administrative Security Unlock CLI

The system SHALL provide an administrative command-line interface (`unlock-user`) allowing operators to restore accounts locked by brute-force protection. The command SHALL accept an explicit username. The target account MUST exist and MUST currently be in state `bloqueado`. A successful unlock SHALL transition the account state from `bloqueado` to `activo` and clear failure tracking metadata. The command SHALL NOT modify accounts in state `inactivo` or `creado`, and SHALL NOT alter the account's role or password.

#### Scenario: Unlock blocked account to active state
- GIVEN an existing account in state bloqueado
- WHEN unlock-user is executed with that username
- THEN the account state MUST transition to activo
- AND the failure tracking metadata MUST be cleared
- AND the user MUST be permitted to authenticate again

#### Scenario: Reject unlock for nonexistent user
- GIVEN a username that does not exist
- WHEN unlock-user is executed with that username
- THEN the command MUST fail with a user-not-found error

#### Scenario: Reject unlock for inactive account
- GIVEN an account in state inactivo
- WHEN unlock-user is executed with that username
- THEN the command MUST reject the operation
- AND the account state MUST remain inactivo

#### Scenario: Reject unlock for created account
- GIVEN an account in state creado
- WHEN unlock-user is executed with that username
- THEN the command MUST reject the operation
- AND the account state MUST remain creado

---

### Requirement: Authenticated Session Establishment and Fixation Protection

Upon successful credential verification, the system SHALL establish a server-side authenticated session. The system SHALL replace/regenerate the pre-authentication session identifier before authenticated privileges become associated with the session to prevent session fixation attacks. An invalid or failed authentication attempt SHALL NOT establish an authenticated session and SHALL NOT upgrade an existing anonymous session.

#### Scenario: Regenerate session ID on successful login
- GIVEN an anonymous session issuing a login request
- WHEN valid credentials for an active account are verified
- THEN the system MUST regenerate the session identifier
- AND the new session MUST be populated with the authenticated user identity
- AND the old session identifier MUST no longer be valid

#### Scenario: Failed login preserves unauthenticated session state
- GIVEN an anonymous session issuing a login request
- WHEN invalid credentials are submitted
- THEN the system MUST NOT associate the session with any user identity
- AND the session identifier MUST NOT gain authenticated privileges

---

### Requirement: Persistent Current-User Authority and Stale-Session Invalidation

For every protected request, the system SHALL revalidate the authenticated account against persistent account data before authorizing the request. The persisted current account state and role SHALL be authoritative. If the user record no longer exists or holds a state other than `activo` (`bloqueado`, `inactivo`, `creado`), the system SHALL immediately invalidate the authenticated session, clear session data, invalidate the browser's ability to reuse the previous authenticated session, and deny handler dispatch. A role change in persistent storage SHALL affect the user's immediate next protected request.

#### Scenario: Immediately revoke access when account transitions to blocked
- GIVEN an authenticated user with an open session
- WHEN the account state in persistent storage is changed to bloqueado
- AND the user submits a subsequent request to a protected route
- THEN the system MUST invalidate the session
- AND the protected handler MUST NOT execute
- AND the response MUST direct the user to /login

#### Scenario: Immediately revoke access when account transitions to inactive
- GIVEN an authenticated user with an open session
- WHEN the account state in persistent storage is changed to inactivo
- AND the user submits a subsequent request to a protected route
- THEN the system MUST invalidate the session
- AND the protected handler MUST NOT execute
- AND the response MUST direct the user to /login

#### Scenario: Immediately apply updated role permissions
- GIVEN an authenticated user with role cajero
- WHEN the user's role in persistent storage is updated to administrador
- AND the user submits a subsequent request to an administrator-only route
- THEN the system MUST resolve the updated role from persistent storage
- AND the user MUST be permitted to execute the administrator operation

#### Scenario: Invalidate session when user record is missing
- GIVEN a session referencing a user identity that does not exist in persistent storage
- WHEN a protected route is requested
- THEN the system MUST invalidate the session
- AND the protected handler MUST NOT execute

---

### Requirement: Role-Aware Inactivity Session Expiration

The system SHALL enforce automatic session expiration based on inactivity duration between authenticated requests. Authenticated sessions for users holding the role `cajero` SHALL expire after 20 minutes of inactivity. Authenticated sessions for other roles (`administrador`, `bodeguero`, `compras`) SHALL expire after a configurable default duration (30 minutes). Unauthenticated requests to public endpoints (such as `GET /health`) and static asset requests SHALL NOT update session activity timestamps. When an inactive session expires, the system SHALL invalidate the session and deny protected handler execution.

#### Scenario: Expire cashier session after 20 minutes of inactivity
- GIVEN an authenticated cajero session whose last activity was more than 20 minutes ago
- WHEN the cashier issues a request to a protected route
- THEN the system MUST treat the session as expired
- AND the session MUST be invalidated
- AND the protected handler MUST NOT execute

#### Scenario: Expire non-cashier session after configured inactivity timeout
- GIVEN an authenticated administrador session whose last activity exceeds the configured timeout
- WHEN the administrator issues a request to a protected route
- THEN the system MUST treat the session as expired
- AND the session MUST be invalidated
- AND the protected handler MUST NOT execute

#### Scenario: Active interaction extends session validity
- GIVEN an authenticated session within its active idle threshold
- WHEN the user issues a request to a protected route
- THEN the request MUST execute normally
- AND the session's last activity timestamp MUST be updated to the current time

---

### Requirement: Complete Session Termination via Logout

The system SHALL provide a `POST /logout` endpoint to terminate authenticated sessions. The logout request SHALL require an active authenticated session and a valid CSRF token. Upon successful processing, the system SHALL:
- remove authenticated identity from the active session
- invalidate server-side authenticated session state
- invalidate the browser's ability to reuse the previous authenticated session
- cause subsequent protected requests using the prior session to be treated as unauthenticated
- redirect with HTTP status 303 See Other to `/login`

#### Scenario: Logout invalidates server session and prevents session reuse
- GIVEN an authenticated session with a valid CSRF token
- WHEN a POST request is sent to /logout
- THEN the system MUST remove authenticated identity from the active session
- AND the system MUST invalidate server-side authenticated session state
- AND the browser MUST NOT be able to reuse the prior authenticated session
- AND the response MUST redirect to /login with status 303 See Other

#### Scenario: Reject protected request following logout
- GIVEN a session that has completed logout
- WHEN that client attempts to access a protected route
- THEN the system MUST deny access as unauthenticated
- AND the protected handler MUST NOT execute

#### Scenario: Reject logout without valid CSRF
- GIVEN an authenticated session
- WHEN a POST request to /logout is sent without a valid CSRF token
- THEN the system MUST reject the request with HTTP status 403 Forbidden
- AND the authenticated session MUST remain intact

---

### Requirement: Single-Role Authorization Model and R1 Route Protection

The system SHALL enforce coarse-grained single-role authorization evaluated on the server before dispatching any protected business handler. Each user account SHALL hold exactly one role. Direct HTTP requests SHALL be subject to server-side role verification regardless of whether corresponding action controls were visible in the client interface. The system SHALL enforce the following route authorization matrix across existing R1 endpoints:
- `administrador`: Authorized for all operational routes (`GET /products`, `POST /categories`, `POST /products`, `POST /products/{id}/price`, `POST /products/{id}/deactivate`, `POST /products/{id}/activate`, `GET /locations`, `POST /locations`, `GET /inventory`, `POST /inventory/stock`, `GET /inventory/counts`, `POST /inventory/counts`).
- `bodeguero`: Authorized for `GET /products`, `GET /locations`, `POST /locations`, `GET /inventory`, `POST /inventory/stock`, `GET /inventory/counts`, `POST /inventory/counts`.
- `cajero`: Authorized for `GET /products` (read-only catalog search).
- `compras`: Authorized for `GET /products` (read-only catalog search).
Any authenticated request targeting a route for which the user's role is not authorized SHALL be rejected with HTTP status 403 Forbidden without redirecting.

#### Scenario: Cashier accesses product catalog
- GIVEN an authenticated user with role cajero
- WHEN a GET request is issued to /products
- THEN the system MUST permit access and render the product catalog

#### Scenario: Cashier denied catalog mutation with 403
- GIVEN an authenticated user with role cajero
- WHEN a POST request is issued to /products or /categories
- THEN the system MUST reject the request with HTTP status 403 Forbidden
- AND no product or category MUST be created

#### Scenario: Purchasing staff accesses product catalog
- GIVEN an authenticated user with role compras
- WHEN a GET request is issued to /products
- THEN the system MUST permit access and render the product catalog

#### Scenario: Purchasing staff denied stock position creation
- GIVEN an authenticated user with role compras
- WHEN a POST request is issued to /inventory/stock
- THEN the system MUST reject the request with HTTP status 403 Forbidden
- AND no stock position MUST be created

#### Scenario: Warehouse staff records physical count
- GIVEN an authenticated user with role bodeguero
- WHEN a valid POST request is issued to /inventory/counts
- THEN the system MUST permit the request and record the observational count

#### Scenario: Warehouse staff denied product price change
- GIVEN an authenticated user with role bodeguero
- WHEN a POST request is issued to /products/1/price
- THEN the system MUST reject the request with HTTP status 403 Forbidden
- AND the product price MUST NOT change

#### Scenario: Administrator executes all operations
- GIVEN an authenticated user with role administrador
- WHEN any registered R1 catalog, location, stock, or count endpoint is requested
- THEN the system MUST permit execution subject to normal business validation

---

### Requirement: Safe Internal Return Destination

When an unauthenticated request targeting a protected GET route is redirected to `/login`, the system SHALL retain the intended destination in server-side session state only if it is a validated relative path internal to the application. The system SHALL reject external URLs and protocol-relative paths (`//`). Upon successful authentication, if the user's role is authorized for the remembered destination, the system SHALL redirect to that destination and clear it from the session. If the user's role is not authorized for the destination, the system SHALL discard the destination and redirect to the default catalog route (`/products`).

#### Scenario: Redirect to remembered internal route after login
- GIVEN an unauthenticated user attempting to access /inventory/counts
- WHEN the user is redirected to /login and successfully authenticates with role bodeguero
- THEN the system MUST redirect the user to /inventory/counts
- AND the remembered destination MUST be cleared from the session

#### Scenario: Fallback to catalog when unauthorized for remembered destination
- GIVEN an unauthenticated user attempting to access /inventory/counts
- WHEN the user is redirected to /login and successfully authenticates with role cajero
- THEN the system MUST NOT redirect the user to /inventory/counts
- AND the system MUST redirect the user to /products
- AND the remembered destination MUST be cleared from the session

#### Scenario: Reject open redirect to external destination
- GIVEN an unauthenticated request with an external or protocol-relative target
- WHEN authentication succeeds
- THEN the system MUST ignore the external destination
- AND the system MUST redirect to the safe default route /products

---

### Requirement: HTTP Login Behavior and Error Disclosure

The system SHALL provide `GET /login` and `POST /login` endpoints. `GET /login` SHALL allow anonymous access and render the login view; if an already-authenticated user with an active session accesses `GET /login`, the system SHALL redirect them to `/products`. `POST /login` SHALL require CSRF validation, accept username and password inputs, enforce password length bounds, verify credentials, track failed attempts, and upon success, replace the pre-authentication session identifier and issue an HTTP 303 See Other redirect. Any authentication failure SHALL return HTTP status 422 with a generic error message that prevents account enumeration and state diagnosis.

#### Scenario: Anonymous user accesses login page
- GIVEN an unauthenticated client
- WHEN a GET request is sent to /login
- THEN the system MUST respond with HTTP status 200 and render the login form

#### Scenario: Already authenticated user redirected from login page
- GIVEN an authenticated user with an active session
- WHEN a GET request is sent to /login
- THEN the system MUST respond with HTTP status 303 See Other redirecting to /products

#### Scenario: Reject login with missing CSRF token
- GIVEN valid login credentials
- WHEN a POST request is sent to /login without a valid CSRF token
- THEN the system MUST reject the request with HTTP status 403 Forbidden
- AND no session MUST be established

#### Scenario: Generic error feedback on authentication failure
- GIVEN an invalid login submission (wrong password, missing user, or non-active account)
- WHEN POST /login is processed
- THEN the system MUST respond with HTTP status 422
- AND the response MUST present generic failure messaging without revealing account existence or status

---

### Requirement: Responsive Login Interface

The login interface SHALL render in accordance with the application's visual design contract, presenting a single-column card layout usable on mobile viewports down to 360px and on desktop screens. Form inputs SHALL include visible label associations and explicit autocomplete attributes. Interactive controls, including the submit button and logout actions, SHALL provide touch-accessible target dimensions of at least 44px.

#### Scenario: Render responsive login form on narrow viewport
- GIVEN a mobile viewport with width 360px
- WHEN the login view is requested
- THEN the form MUST render in a single column without horizontal scrolling
- AND interactive input fields and buttons MUST have height of at least 44px

---

### Requirement: Sensitive Credential Logging Prohibition

The system SHALL NOT output plaintext passwords, password hashes, session identifiers, or CSRF tokens to application logs, error reports, or diagnostic interfaces. Technical error logging SHALL record correlation identifiers and technical exceptions without exposing credential secrets.

#### Scenario: Application logs exclude sensitive authentication tokens
- GIVEN an authentication operation or unexpected failure
- WHEN diagnostic logs are written
- THEN the logged content MUST NOT contain plaintext passwords, password hashes, session identifiers, or CSRF tokens

---

### Requirement: R1 Domain Invariant Preservation

Access control enforcement SHALL govern route authorization without altering the business semantics or invariants of existing R1 catalog, location, stock, and count domains. Physical counts SHALL remain purely observational without mutating stock quantities. Stock positions SHALL remain unique per product-location pair with 3-decimal precision. Product reactivation and deactivation SHALL preserve historical identifiers without row deletion.

#### Scenario: Authenticated count recording leaves stock quantity untouched
- GIVEN an authenticated bodeguero user and an existing stock position
- WHEN a count is recorded for that stock position
- THEN the observational count record MUST be stored
- AND the stock position's registered quantity MUST NOT be modified

#### Scenario: Authenticated stock position enforces unique constraint
- GIVEN an authenticated user and an existing stock position for a product and location
- WHEN a duplicate stock position is submitted for the same product and location
- THEN the system MUST reject the submission with a validation error
- AND the existing stock quantity MUST remain unchanged
