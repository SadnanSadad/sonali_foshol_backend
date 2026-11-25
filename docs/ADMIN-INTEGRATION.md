# Sonali Foshol — Admin Integration

How the staff admin panel (`sonali-storefront-admin`) talks to this API.

- Base URL: `http://localhost:8000` (dev)
- All endpoints are prefixed with `/api` (no `/v1`).
- Admin auth uses **Sanctum SPA cookie sessions + CSRF** (no bearer tokens).
- `/api/auth/admin/*` handles login/session/2FA/password.
- `/api/admin/*` handles the management resources.

> The current admin SPA is still on a bearer-token contract (`/auth/login`, `Authorization:
> Bearer <token>`). To use this API, switch to cookie auth as described in §2 and update
> paths to the `/api` namespaces below.

---

## 1. Environment

```dotenv
VITE_USE_MOCK_DATA=false
VITE_API_BASE_URL=http://localhost:8000
VITE_STOREFRONT_URL=http://localhost:5174
```

The admin dev origin (`http://localhost:5173`) is already stateful in
`config/sanctum.php` and allowed in `config/cors.php` (override via
`SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS`).

Seeded superadmin (from demo seed):

| Field | Value |
|---|---|
| email | `admin@sonalifoshol.com` |
| username | `admin` |
| password | `sonali123` |

---

## 2. Response envelope

Success:

```json
{ "success": true, "data": { }, "meta": { } }
```

`meta` appears on paginated lists:

```json
"meta": { "page": 1, "limit": 10, "total": 29, "totalPages": 3 }
```

Error:

```json
{ "success": false, "message": "The given data was invalid.", "errors": { "field": ["msg"] } }
```

Status codes: `422` validation, `401` unauthenticated, `403` unauthorized,
`404` not found, `409` conflict (e.g. invalid transition, category in use), `429` rate limited.

Unwrap `data.data`. Every response carries `X-Request-Id`.

---

## 3. Axios / auth configuration

```ts
export const axiosInstance = axios.create({
  baseURL: env.apiBaseUrl,
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

export async function ensureCsrfCookie() {
  await axios.get(`${env.apiBaseUrl}/sanctum/csrf-cookie`, { withCredentials: true })
}
```

Rules:

- Call `ensureCsrfCookie()` before login, logout, and any non-GET request when no CSRF
  cookie exists.
- On `419`, refresh the CSRF cookie and retry once.
- On `401`, clear in-memory auth and redirect to `/login`.
- Do **not** clear auth on `403` (role/permission issue).
- Do not persist any token in localStorage.

Auth store (cookie session — no token):

```ts
interface AuthState {
  user: AdminUser | null
  isAuthenticated: boolean
  isBootstrapping: boolean
  setUser: (user: AdminUser) => void
  clear: () => void
}
```

On app load, call `GET /api/auth/admin/me`; `401` means logged out. Remove the legacy
`sfo-admin-token` key.

---

## 4. Auth + 2FA

### `POST /api/auth/admin/login`

Body: `{ "login": "admin", "password": "sonali123", "remember": false }`
(`login` may be email or username; `email` is accepted as an alias.)

No 2FA:

```json
{ "success": true, "data": { "user": { "id": 1, "name": "...", "email": "...", "username": "admin", "role": "superadmin", "avatar": "...", "isActive": true, "lastLoginAt": "...", "twoFactorEnabled": false } } }
```

2FA enabled:

```json
{ "success": true, "data": { "two_factor_required": true, "challenge_token": "<opaque>" } }
```

### `POST /api/auth/admin/2fa/challenge`

Body: `{ "challenge_token": "...", "code": "123456" }`. Accepts an authenticator code or
a recovery code. Returns the same `user` payload on success.

### Other auth routes

| Function | Method + path | Body |
|---|---|---|
| me | `GET /api/auth/admin/me` | — |
| logout | `POST /api/auth/admin/logout` | — |
| update password | `PUT /api/auth/admin/password` | `current_password`, `password` (min 12, confirmed) |
| forgot password | `POST /api/auth/admin/forgot-password` | `email` |
| reset password | `POST /api/auth/admin/reset-password` | `email`, `token`, `password` (min 12, confirmed) |
| 2FA enable | `POST /api/auth/admin/2fa/enable` | — → `secret`, `qr_svg`, `recovery_codes` |
| 2FA confirm | `POST /api/auth/admin/2fa/confirm` | `code` |
| 2FA disable | `POST /api/auth/admin/2fa/disable` | `password`, `code` |
| 2FA recovery | `POST /api/auth/admin/2fa/recovery-codes` | `password` |

`/api/auth/login`, `/api/auth/me`, `/api/auth/logout` are legacy aliases to the admin
auth controller.

Roles: `superadmin`, `admin`. User management and settings update require `superadmin`.

---

## 5. Rate limits

| Area | Limit |
|---|---|
| `admin-login` | 5/min per login+IP |
| `2fa-challenge` | 5/min per challenge+IP |
| `forgot-password` | 3/min per email+IP |
| `admin-api` | 300/min per admin |
| `uploads` | 20/min per admin |

Exceeding returns `429` `{ "success": false, "message": "..." }`.

---

## 6. Resource shapes

### AdminUser

```json
{ "id": 1, "name": "Sonali Owner", "email": "admin@sonalifoshol.com", "username": "admin", "role": "superadmin", "avatar": "http://localhost:8000/storage/avatar-admin.svg", "isActive": true, "lastLoginAt": "...", "twoFactorEnabled": false }
```

### AdminProduct (`GET /api/admin/products`, `/api/admin/products/{id|slug}`)

```json
{
  "id": 1, "slug": "miniket-rice", "nameBn": "...", "nameEn": "...",
  "category": "rice", "originBn": "...", "image": "http://.../storage/...",
  "images": ["http://.../storage/..."], "badgeBn": "...", "inStock": true,
  "stock": 42, "status": "active", "shortDescBn": "...", "fullDescBn": "...",
  "nutritionHighlights": ["..."], "nutritionFacts": [{ "labelBn": "...", "valueBn": "..." }],
  "cookingTipsBn": "...",
  "weights": [{ "label": "১ কেজি", "grams": 1000, "price": 120, "mrp": 150, "stock": 20, "sku": "..." }],
  "createdAt": "...", "updatedAt": "..."
}
```

### Order (`GET /api/admin/orders`, `/api/admin/orders/{id|orderNumber}`)

```json
{
  "id": 7, "orderNumber": "SFO-2026-0007", "createdAt": "...", "expectedDeliveryDate": "...",
  "status": "pending",
  "customer": { "name": "...", "phone": "01712345678", "district": "ঢাকা", "address": "...", "note": "..." },
  "deliveryZone": "inside_dhaka", "paymentMethod": "cod",
  "items": [{ "productId": 1, "slug": "...", "title": "...", "image": "http://...", "weightLabel": "১ কেজি", "unitPrice": 120, "qty": 2 }],
  "subtotal": 240, "deliveryFee": 60, "discount": 0, "total": 300,
  "rejection": null,
  "courier": null,
  "statusHistory": [{ "status": "pending", "at": "...", "by": "System", "note": "Order placed by customer" }]
}
```

When rejected, `rejection` = `{ "reason", "feedback", "by", "at" }`. When shipped,
`courier` = `{ "name", "trackingId" }`.

### Customer (`GET /api/admin/customers`, `/api/admin/customers/{id}`)

```json
{ "id": 1, "name": "...", "phone": "01712345678", "district": "ঢাকা", "address": "...", "orderCount": 3, "totalSpent": 1500, "flagged": false, "createdAt": "...", "lastOrderAt": "..." }
```

### Review (`GET /api/admin/reviews`)

```json
{ "id": 1, "productId": 1, "productNameBn": "...", "author": "...", "location": "ঢাকা", "rating": 5, "text": "...", "status": "approved", "createdAt": "..." }
```

`productId` / `productNameBn` are `null` for general testimonials.

### Settings (`GET /api/admin/settings`)

```json
{
  "storeName": "...", "storeNameBn": "...", "brandTaglineBn": "...",
  "whatsappNumber": "01XXXXXXXXX", "facebookUrl": "...", "messengerUrl": "...",
  "deliveryFeeInside": 60, "deliveryFeeOutside": 120, "freeShippingThreshold": 2000,
  "lowStockThreshold": 10, "phoneDisplay": "...", "email": "...", "addressBn": "...",
  "insideDeliveryDays": 2, "outsideDeliveryDays": 4
}
```

### CalendarEvent (`GET /api/admin/calendar/events`)

```json
{ "id": 1, "title": "...", "type": "admin", "start": "...", "end": "...", "allDay": false, "note": "...", "orderId": null }
```

Delivery events are synthetic: `id` = `"delivery-<orderId>"`, `type` = `"delivery"`,
`allDay` = true. `PUT /api/admin/calendar/events/delivery-{id}` reschedules the order's
expected delivery date.

---

## 7. Admin endpoints

All under `/api/admin/*`, session + `admin.active` + `throttle:admin-api`.

### Dashboard

| Function | Path | Notes |
|---|---|---|
| summary | `GET /dashboard/summary` | today orders/revenue, pending, low stock, revenue, AOV, change % |
| sales | `GET /dashboard/sales?range=7d\|30d\|90d` | `[{ date, revenue, orders }]` |
| status breakdown | `GET /dashboard/status-breakdown` | `[{ status, count, total }]` (all statuses) |
| top products | `GET /dashboard/top-products?limit=5` | `[{ productId, nameBn, slug, image, unitsSold, revenue }]` |

### Products

| Function | Method + path |
|---|---|
| list | `GET /products` |
| show | `GET /products/{id\|slug}` |
| create | `POST /products` |
| update | `PUT /products/{id\|slug}` |
| delete | `DELETE /products/{id\|slug}` |
| bulk | `PATCH /products/bulk` |

`GET /products` query params:

| Param | Values |
|---|---|
| `category` | slug or `all` |
| `search` | name bn/en, slug, origin |
| `stock` | `all` \| `in` \| `low` \| `out` |
| `status` | `all` \| `active` \| `draft` |
| `sort` | `newest` \| `name` \| `price` \| `stock` |
| `page` | default 1 |
| `limit` | default 10, max 100 |

Product create/update body:

```json
{
  "slug": "miniket-rice",
  "nameBn": "...", "nameEn": "...", "category": "rice", "originBn": "...",
  "image": "/storage/products/....svg", "images": ["/storage/products/....svg"],
  "badgeBn": "...", "inStock": true, "status": "active",
  "shortDescBn": "...", "fullDescBn": "...",
  "nutritionHighlights": ["..."],
  "nutritionFacts": [{ "labelBn": "...", "valueBn": "..." }],
  "cookingTipsBn": "...",
  "weights": [{ "label": "১ কেজি", "grams": 1000, "price": 120, "mrp": 150, "stock": 20, "sku": "..." }]
}
```

Notes: `category` is the category slug. `weights.*.mrp >= price`. Each weight `grams` unique
per product. `image`/`images` are paths returned by the upload endpoint. `POST /products/bulk`
takes `{ "action": "publish|unpublish|delete", "ids": [] }`.

### Categories

| Function | Method + path |
|---|---|
| list | `GET /categories` |
| create | `POST /categories` |
| update | `PUT /categories/{id}` |
| delete | `DELETE /categories/{id}` |

Body: `{ "slug", "nameEn", "nameBn", "order"?, "hidden"? }`. Delete returns `409` if the
category still has products.

### Orders

| Function | Method + path |
|---|---|
| list | `GET /orders` |
| show | `GET /orders/{id\|orderNumber}` |
| update status | `PATCH /orders/{id}/status` |

`GET /orders` query params: `status` (comma list or `all`), `search` (order number,
customer name, phone), `from`, `to` (Asia/Dhaka dates), `zone`, `payment`
(`cod|whatsapp|messenger`), `page`, `limit` (default 10, max 100).

`PATCH /orders/{id}/status` body:

```json
{ "status": "shipped", "courier": "Pathao", "trackingId": "T123", "note": "..." }
```

Rejected:

```json
{ "status": "rejected", "reason": "out_of_stock", "feedback": "..." }
```

`reason` ∈ `out_of_stock | unreachable | invalid_address | fake_order | outside_area | other`
(required when rejecting). Transitions are validated server-side; illegal transitions
return `409`. Rejecting/cancelling restores stock once.

Allowed transitions: `pending → confirmed/rejected/cancelled`, `confirmed → processing/cancelled`,
`processing → shipped/cancelled`, `shipped → delivered`.

### Customers

| Function | Method + path |
|---|---|
| list | `GET /customers` |
| show | `GET /customers/{id}` |
| orders | `GET /customers/{id}/orders` |
| flag | `PATCH /customers/{id}/flag` |

`GET /customers` params: `search`, `flagged` (`all|yes|no`), `sort`
(`last_order|spent|orders|name`), `page`, `limit` (default 10, max 100). `PATCH .../flag`
body: `{ "flagged": true }`.

### Reviews

| Function | Method + path |
|---|---|
| list | `GET /reviews` |
| update status | `PATCH /reviews/{id}` |
| delete | `DELETE /reviews/{id}` |

`GET /reviews` params: `status` (`all|pending|approved|hidden`), `search`, `rating`,
`page`, `limit` (default 10, max 100). `PATCH /reviews/{id}` body: `{ "status": "approved" }`.

### Calendar

| Function | Method + path |
|---|---|
| list | `GET /calendar/events?from=&to=` |
| create | `POST /calendar/events` |
| update | `PUT /calendar/events/{id}` |
| delete | `DELETE /calendar/events/{id}` |

Body: `{ "title", "start", "end"?, "allDay"?, "note"?, "orderId"? }`. `from`/`to` default
to the current month (Asia/Dhaka). Deleting a `delivery-*` id is a no-op success.

### Settings

| Function | Method + path | Role |
|---|---|---|
| show | `GET /settings` | any admin |
| update | `PUT /settings` | superadmin |

Update accepts any subset of the settings fields in §6. `whatsappNumber` is normalized to
`01XXXXXXXXX` and validated.

### Users (superadmin only)

| Function | Method + path |
|---|---|
| list | `GET /users` |
| create | `POST /users` |
| update | `PATCH /users/{id}` |
| reset password | `POST /users/{id}/reset-password` |

Create body: `{ "name", "email", "username", "password" (min 12, confirmed), "role": "superadmin|admin" }`.
Update body: `{ "name"?, "role"?, "isActive"? }`. You cannot deactivate or demote yourself.

### Uploads

`POST /uploads` — `multipart/form-data` with field `file` (jpg/jpeg/png/webp, max 5 MB).
Returns `201` `{ "url": "http://localhost:8000/storage/products/<uuid>.webp" }`. Use the
returned `url`/path as the product `image`/`images` value.

---

## 8. Type changes

The admin SPA types should move to the shapes above. Key deltas from the current types:

- `AdminRole`: `'owner' | 'staff'` → `'superadmin' | 'admin'`.
- `AuthSession` (token) → session cookie; `login` returns `user`, not a token.
- `AdminUser.username` and `avatar` nullable; add `isActive`, `lastLoginAt`, `twoFactorEnabled`.
- `WeightVariant.sku` optional; admin product adds `stock`, `nutritionFacts`, `cookingTipsBn`.
- `Review.productId` / `productNameBn` nullable; `location` nullable.
- `StoreSettings` adds `brandTaglineBn`, `phoneDisplay`, `email`, `addressBn`,
  `insideDeliveryDays`, `outsideDeliveryDays`.
- Order `deliveryZone` is underscored in admin (`inside_dhaka`), hyphenated in storefront.

---

## 9. Verification checklist

- `npm run typecheck` and `npm run build`.
- Login (email and username); refresh restores session via `/me`; logout clears it.
- Products: list, create, edit, upload; bulk publish/unpublish.
- Orders: list, detail, confirm → ship (courier) → deliver; reject restores stock.
- Customers: list, detail, orders, flag.
- Reviews: approve / hide / delete.
- Calendar: create / update / delete; delivery reschedule.
- Settings: update (superadmin); low-stock threshold affects dashboard.
- Users (superadmin): create / update / reset password; role gate on 403.
- 2FA: enable → confirm → recovery codes → disable.
- 401 on expired session redirects to login; 403 does not.

Known issue: `npm run lint` may fail because Windows Application Control blocks oxlint's
native binding. Treat it as an environment blocker, not a code failure.