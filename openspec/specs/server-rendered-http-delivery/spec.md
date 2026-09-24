# Server-Rendered HTTP Delivery Specification

## Purpose

Define safe HTML-over-the-wire delivery for full navigation and HTMX interactions without duplicating application behavior.
## Requirements
### Requirement: Central HTTP Routing

Every HTTP request MUST pass through a central route contract that distinguishes path and method. Protected business routes MUST require an authenticated session and an authorized role before handler dispatch. Unauthenticated requests to protected routes MUST be redirected to the login endpoint, and unauthorized authenticated requests MUST be rejected with status 403. Unknown paths MUST return 404; known paths invoked with unsupported methods MUST return 405 and identify allowed methods.
(Previously: Route dispatching executed matching handlers without pre-handler authentication or role authorization guards.)

#### Scenario: Dispatch a known route
- GIVEN a registered path and method
- WHEN a request matches both and satisfies any required authentication or authorization guard
- THEN the corresponding server-rendered response MUST be dispatched

#### Scenario: Redirect unauthenticated browser request
- GIVEN an unauthenticated browser user
- WHEN a protected route is requested via a GET request
- THEN the system MUST respond with HTTP status 303
- AND the Location header MUST redirect to /login
- AND the protected handler MUST NOT execute

#### Scenario: Intercept unauthenticated HTMX request
- GIVEN an unauthenticated or expired session
- WHEN a protected route is requested via HTMX
- THEN the system MUST respond with HTTP status 200
- AND the response MUST include header HX-Redirect set to /login
- AND no partial login fragment MUST be rendered into the target element
- AND the protected handler MUST NOT execute

#### Scenario: Reject authenticated user lacking role authorization
- GIVEN an authenticated user whose active role lacks permission for the requested route
- WHEN the user attempts to access the route
- THEN the system MUST return HTTP status 403 Forbidden
- AND the system MUST NOT redirect the user to /login
- AND the protected handler MUST NOT execute

#### Scenario: Dispatch a public route without authentication
- GIVEN a public route such as GET /health or GET /login
- WHEN an unauthenticated user requests the route
- THEN the route handler MUST be dispatched without requiring credentials

#### Scenario: Reject an unsupported request
- GIVEN a path is unknown or its method is unsupported
- WHEN the request is dispatched
- THEN it MUST return 404 for the unknown path or 405 for the unsupported method
- AND a 405 response MUST identify the allowed methods

---

### Requirement: Full and Partial HTML Representations

A route MUST render a complete page for normal navigation and MAY render a named fragment for an HTMX request. Both representations MUST use the same use-case result and MUST NOT duplicate use-case logic. Core actions MUST remain usable without custom JavaScript.

#### Scenario: Request both representations

- GIVEN one concept-free route supports normal and HTMX requests
- WHEN each request form is sent
- THEN normal navigation MUST receive a complete HTML document
- AND HTMX MUST receive only the intended fragment from the same outcome

#### Scenario: HTMX is unavailable

- GIVEN browser scripting or HTMX is unavailable
- WHEN a user follows the route's normal HTML interaction
- THEN the server MUST provide an equivalent full-page outcome

### Requirement: Safe Template Output

Templates MUST apply contextual escaping to untrusted values and MUST keep shared layout, full-page, and fragment boundaries valid. Rendering failure MUST NOT return a partial template or raw exception.

#### Scenario: Render untrusted text

- GIVEN a rendered value contains HTML control characters
- WHEN it appears in a page or fragment
- THEN it MUST be escaped for its output context

### Requirement: Validation, Redirects, and Notifications

Server validation MUST be authoritative. Invalid submissions MUST return 422 with field feedback as a full page or HTMX fragment. Successful normal submissions MUST use Post/Redirect/Get with HTTP status 303 See Other; HTMX responses MUST use documented `HX-Redirect` and `HX-Trigger` conventions where navigation or notifications are required.
(Previously: Successful submissions did not specify the exact 303 status code for Post/Redirect/Get redirects.)

#### Scenario: Reject invalid input
- GIVEN a normal or HTMX submission contains invalid input
- WHEN validation runs
- THEN the response MUST have status 422
- AND it MUST preserve safe input and expose field-specific feedback in the matching representation

#### Scenario: Complete a valid submission
- GIVEN a submission is valid
- WHEN the state-changing action completes
- THEN normal navigation MUST redirect using HTTP status 303 See Other to a safe GET target
- AND HTMX MUST communicate navigation or notification through the documented HX headers

### Requirement: Request Safety and Failure Boundary

Every state-changing browser request MUST require a valid CSRF token, including HTMX requests. Unhandled failures MUST return a generic 500 response and MUST be logged once at the HTTP boundary with a correlation identifier; responses and logs MUST NOT expose secrets.

#### Scenario: Reject invalid CSRF

- GIVEN a state-changing request has a missing or invalid CSRF token
- WHEN it reaches the HTTP boundary
- THEN it MUST be rejected without executing the action

#### Scenario: Handle an unexpected failure

- GIVEN request processing raises an unhandled failure
- WHEN the boundary handles it
- THEN the client MUST receive a generic 500 response with a correlation identifier
- AND the correlated log MUST retain diagnostic context without secrets

### Requirement: HTTP Infrastructure Proof

The canonical `composer test` command MUST prove route dispatch, full/fragment rendering, validation, CSRF rejection, and generic failure behavior through concept-free HTTP cases.

#### Scenario: Execute HTTP contract tests

- GIVEN the test environment is configured
- WHEN `composer test` runs
- THEN the HTTP delivery contracts MUST be exercised without an R1–R10 concept

### Requirement: Infrastructure Health Liveness and Probe Boundaries

The system MUST provide an anonymous GET /health endpoint returning a minimal availability check without exposing database credentials, operational records, session diagnostics, or internal system state. State-changing probe endpoints (POST /health) SHALL NOT be available in production and MAY exist only outside production environments for integration harness testing.

#### Scenario: Access public liveness probe
- GIVEN an unauthenticated client
- WHEN a GET request is issued to /health
- THEN the system MUST respond with HTTP status 200
- AND the response MUST NOT expose database credentials, operational records, or session identifiers

#### Scenario: Reject probe mutation in production
- GIVEN the application is running in a production or disallowed environment
- WHEN a POST request is issued to /health
- THEN the system MUST respond with HTTP status 405 Method Not Allowed
- AND the response MUST include header Allow set to GET
- AND the response body MUST be Method Not Allowed
- AND the diagnostic probe mutation and session flash MUST NOT be executed

---

### Requirement: Authenticated Layout Shell and Action Visibility

The rendered application shell MUST expose the authenticated user's username, human-readable role badge, and a touch-accessible logout action. The presentation layer MUST conditionally suppress operational action controls that the user's role is not authorized to execute. UI control suppression MUST remain an ergonomic convenience, while server-side authorization guards remain authoritative.

#### Scenario: Render authenticated shell context
- GIVEN an authenticated user session
- WHEN an operational page is rendered
- THEN the topbar and navigation shell MUST display the username and human-readable role
- AND a touch-accessible logout action with minimum target size of 44px MUST be available

#### Scenario: Suppress unauthorized mutation controls in UI
- GIVEN an authenticated user with role cajero
- WHEN the product catalog page is rendered
- THEN the user MUST view catalog items
- AND action controls for creating categories, registering products, updating prices, or deactivating products MUST be omitted from the rendered HTML
