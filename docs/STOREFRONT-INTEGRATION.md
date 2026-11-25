# Sonali Foshol — Storefront Integration

How the customer-facing storefront (`sonali-foshol-storefront`) talks to this API.

- Base URL: `http://localhost:8000` (dev)
- All endpoints are prefixed with `/api` (no `/v1`).
- Public read endpoints require no auth. Order creation is public (guest checkout).
- Customer account endpoints use Sanctum cookie sessions.

---

## 1. Environment

```dotenv
VITE_USE_MOCK_DATA=false
VITE_API_BASE_URL=http://localhost:8000
```

Keep `VITE_USE_MOCK_DATA=true` for offline/demo work.

The API must list the storefront origin as stateful and allowed. Dev defaults already
include `localhost:5173` and `localhost:5174` (see `config/sanctum.php`,
`config/cors.php`, and `SANCTUM_STATEFUL_DOMAINS` in `.env`).

---

## 2. Response envelope

Every endpoint returns a JSON envelope.

Success:

```json
{ "success": true, "data": { }, "meta": { } }
```

`meta` is present only on paginated responses:

```json
"meta": { "page": 1, "limit": 10, "total": 42, "totalPages": 5 }
```

Error:

```json
{ "success": false, "message": "The given data was invalid.", "errors": { "field": ["msg"] } }
```

- Validation errors → `422` with `errors`.
- Auth required → `401` `{ "success": false, "message": "Unauthenticated." }`.
- Not found → `404` `{ "success": false, "message": "Resource not found." }`.
- Rate limited → `429`.

Unwrap `data.data` in the client. A `X-Request-Id` header is echoed on every response
and can be logged for support.

---

## 3. Endpoint map

| Storefront function | Method + path | Auth |
|---|---|---|
| `getStoreSettings` | `GET /api/settings` | public |
| `getCategories` | `GET /api/categories` | public |
| `getAllProducts` | `GET /api/products` | public |
| `getProductBySlug` | `GET /api/products/{slug}` | public |
| `getRelatedProducts` | `GET /api/products/{slug}/related?limit=4` | public |
| `getReviews` | `GET /api/reviews` | public |
| `submitOrder` | `POST /api/orders` | public (guest) |
| order tracking | `POST /api/orders/lookup` | public |
| customer orders | `GET /api/storefront/customer/orders` | customer session |
| customer order detail | `GET /api/storefront/customer/orders/{orderNumber}` | customer session |

> The `/api/products`, `/api/categories`, `/api/settings`, `/api/reviews`, `/api/orders`
> paths are also mirrored under `/api/storefront/*`. Both resolve to the same controllers.

---

## 4. Resource shapes

### Product (`GET /api/products`, `/api/products/{slug}`)

```json
{
  "id": 1,
  "slug": "miniket-rice",
  "nameBn": "মিনিকেট চাল",
  "nameEn": "Miniket Rice",
  "category": "rice",
  "originBn": "বাংলাদেশ",
  "image": "http://localhost:8000/storage/products/....svg",
  "images": ["http://localhost:8000/storage/products/....svg"],
  "badgeBn": "সেরা বিক্রয়",
  "inStock": true,
  "shortDescBn": "...",
  "fullDescBn": "...",
  "nutritionHighlights": ["..."],
  "nutritionFacts": [{ "labelBn": "শক্তি", "valueBn": "..." }],
  "cookingTipsBn": "...",
  "weights": [
    { "label": "১ কেজি", "grams": 1000, "price": 120, "mrp": 150 }
  ]
}
```

Query params for `GET /api/products`:

| Param | Values | Notes |
|---|---|---|
| `category` | slug or `all` | `all` returns every active product |
| `search` | string | matches name (bn/en), origin, short desc |
| `sort` | `default` \| `price-asc` \| `price-desc` | default = newest |

### Category (`GET /api/categories`)

```json
{ "id": 1, "slug": "rice", "nameEn": "Rice", "nameBn": "চাল", "order": 1, "hidden": false, "productCount": 5 }
```

### Review (`GET /api/reviews`)

```json
{ "id": 1, "customerName": "...", "location": "ঢাকা", "rating": 5, "commentBn": "..." }
```

Only `approved` reviews are returned. `?limit` (default 20, max 50).

### Settings (`GET /api/settings`)

```json
{
  "brandNameBn": "...",
  "brandTaglineBn": "...",
  "whatsappNumber": "01XXXXXXXXX",
  "facebookPageUrl": "https://facebook.com/...",
  "messengerUrl": "https://m.me/...",
  "insideDhakaDelivery": 60,
  "outsideDhakaDelivery": 120,
  "freeShippingThreshold": 2000,
  "phoneDisplay": "+৮৮০...",
  "email": "hello@sonalifoshol.com",
  "addressBn": "..."
}
```

`whatsappNumber` is normalized to local `01XXXXXXXXX` format.

---

## 5. Checkout — `POST /api/orders`

Server is authoritative for all totals. Do **not** send or trust `subtotal`,
`deliveryFee`, or `total`.

Request:

```json
{
  "clientRequestId": "b7c1...-uuid",
  "customer": {
    "name": "ক্রেতা নাম",
    "phone": "01712345678",
    "email": "buyer@example.com",
    "district": "ঢাকা",
    "address": "বিস্তারিত ঠিকানা (কমপক্ষে ১০ অক্ষর)",
    "note": "কল দিয়ে আসুন"
  },
  "items": [
    { "slug": "miniket-rice", "grams": 1000, "qty": 2 }
  ],
  "deliveryZone": "inside-dhaka",
  "paymentMethod": "cod"
}
```

Rules:

- `clientRequestId` (uuid) is optional but recommended. Re-sending the same value
  returns the existing order (idempotent retry) instead of creating a duplicate.
- Each item needs `productId` **or** `slug`, and `grams` **or** `weightLabel`.
- `deliveryZone` accepts `inside-dhaka` / `outside-dhaka` (hyphen or underscore).
- `paymentMethod` ∈ `cod` | `whatsapp` | `messenger`.
- `customer.phone` is normalized (`8801…` / `1…` → `01…`) and must be a valid BD mobile
  (`^01[3-9]\d{8}$`).
- `customer.email` is optional but enables email notifications.

Success `201` (or `200` when idempotent replay), `data` is an order receipt:

```json
{
  "orderId": "SFO-2026-0007",
  "orderNumber": "SFO-2026-0007",
  "id": 7,
  "createdAt": "2026-11-21T12:00:00+00:00",
  "customer": { "name": "...", "phone": "01712345678", "email": "...", "district": "ঢাকা", "address": "...", "note": "..." },
  "items": [
    { "productId": 1, "slug": "miniket-rice", "title": "মিনিকেট চাল", "weightLabel": "১ কেজি", "grams": 1000, "unitPrice": 120, "qty": 2 }
  ],
  "deliveryZone": "inside-dhaka",
  "paymentMethod": "cod",
  "subtotal": 240,
  "deliveryFee": 60,
  "total": 300
}
```

Delivery fee: `free` when `subtotal >= freeShippingThreshold`, else the zone fee from
settings. Expected delivery date is computed server-side from settings
(`inside_delivery_days` / `outside_delivery_days`).

Persist the receipt in `sessionStorage` (e.g. `sonali-foshol:last-order`) for the success
page. Disable submit while pending; on failure, allow retry with the same `clientRequestId`.

---

## 6. Order tracking — `POST /api/orders/lookup`

`phone` is required; `orderNumber` is optional.

- Both provided: `data` is a **single object** (the matching order).
- Only `phone`: `data` is an **array** of all orders placed with that phone (newest first).
  The array may be empty (`[]`) when the phone has no orders.

```json
{ "phone": "01712345678" }
```

```json
{ "phone": "01712345678", "orderNumber": "SFO-2026-0007" }
```

### Response shapes

Phone-only (array) — matches the storefront tracking list:

```json
{
  "success": true,
  "data": [
    {
      "orderNumber": "SFO-2026-8494",
      "status": "pending",
      "createdAt": "2026-11-21T17:52:29.000000Z",
      "expectedDeliveryDate": "2026-11-23T17:52:29.000000Z",
      "customer": { "name": "Sadnan Sadad", "district": "ঢাকা" },
      "items": [
        { "slug": "soybean-oil", "title": "সয়াবিন তেল", "weightLabel": "১ লিটার", "grams": 1000, "unitPrice": 165, "qty": 1 }
      ],
      "subtotal": 165,
      "deliveryFee": 60,
      "total": 225,
      "statusHistory": [
        { "status": "pending", "at": "2026-11-21T17:52:29.000000Z", "note": "Order placed by customer" }
      ]
    }
  ]
}
```

Phone + orderNumber — `data` is that same object (not wrapped in an array).

Errors: unknown phone/order combination → `404` `{ "success": false, "message": "Order not found." }`;
invalid phone → `422` with `errors.phone`.

### How the storefront implements it

1. **Phone required, order number optional.** Validate phone client-side against
   `^01[3-9]\d{8}$` before calling the API (the API also validates and normalizes
   `8801…` / `1…` → `01…`).
2. **Branch on whether order number was entered:**
   - Order number present → call lookup with both; `data` is a single object → render
     the order detail directly.
   - Only phone → call lookup with phone; `data` is an array → render the list.
     Selecting a row shows that order's detail (reuse the array item; no second call
     needed since list items already contain `items` + `statusHistory`).
3. **Type narrowing.** The client types the response as
   `OrderTrackingResult | OrderTrackingResult[]` and uses `Array.isArray(data)` to
   distinguish the two shapes. See `lookupOrder` + `isOrderTrackingArray` in
   `src/api/order.api.ts`.
4. **Detail view** renders: order number, status badge, created date, expected
   delivery date, customer name/district, line items (title × qty + line total),
   subtotal / delivery fee / total, and a status timeline built from `statusHistory`
   (`pending → confirmed → processing → shipped → delivered`, with
   `rejected`/`cancelled` as terminal).
5. **Empty / not found.** Empty array → "no orders found" message; `404` → friendly
   not-found message.
6. **Deep-link from success page.** After checkout, the success page links to
   `/order-tracking?phone=<phone>&orderNumber=<orderNumber>` so the tracking form is
   pre-filled. The tracking page reads `phone` / `orderNumber` from the query string.

Reference implementation files:

- `src/api/order.api.ts` — `lookupOrder(params)`, `isOrderTrackingArray()`
- `src/types/order.types.ts` — `OrderTrackingResult`, `OrderLookupParams`
- `src/constants/orderStatus.ts` — status labels, badge classes, flow order
- `src/pages/OrderTrackingPage.tsx` — form + list/detail flow
- `src/components/order-tracking/` — `OrderDetail`, `OrderListItem`,
  `OrderStatusBadge`, `OrderStatusTimeline`

---

## 7. Customer accounts (optional)

Sanctum cookie/session auth. Set `withCredentials: true` and bootstrap the CSRF cookie
before any non-GET request.

| Function | Method + path |
|---|---|
| register | `POST /api/auth/customer/register` |
| login | `POST /api/auth/customer/login` |
| me | `GET /api/auth/customer/me` |
| logout | `POST /api/auth/customer/logout` |
| update password | `PUT /api/auth/customer/password` |
| forgot password | `POST /api/auth/customer/forgot-password` |
| reset password | `POST /api/auth/customer/reset-password` |
| account orders | `GET /api/storefront/customer/orders` |
| account order | `GET /api/storefront/customer/orders/{orderNumber}` |

- Register: `name`, `phone`, `email`, `password` (min 8, `confirmed`), optional `district`, `address`.
- Login: `email`, `password`. Customer `data` returns `{ id, name, phone, email, district, address }`.
- Account orders are paginated (`?limit` default 10, max 50) and include only orders
  linked to the logged-in customer. Guest orders appear only in lookup.

If account UI is not built, these endpoints can remain unused.

---

## 8. Axios configuration

```ts
export const axiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000',
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json' },
})
```

Public reads and guest order creation work without CSRF. Only customer-account writes
(register/login/logout/password) and any future session-bound action require the
`sanctum/csrf-cookie` bootstrap and the `X-XSRF-TOKEN` header.

---

## 9. Rate limits

| Area | Limit |
|---|---|
| `public-read` (GET catalog) | 120/min per IP |
| `order-create` | 10/min per IP |
| `order-lookup` | 5/min per IP |
| `customer-login` / register | 5/min per email+IP |
| `forgot-password` | 3/min per email+IP |

Exceeding a limit returns `429` `{ "success": false, "message": "..." }`.

---

## 10. Verification checklist

- `pnpm typecheck` and `pnpm build`.
- Home products load; category filter + search work.
- Product detail loads; related products load.
- Cart totals match `GET /api/settings`.
- Checkout creates an order; receipt totals are server-calculated.
- New order appears in admin; stock decrements.
- Order tracking by phone only works (list of orders, select one to see detail).
- Order tracking by phone + order number works (single detail).
- Tracking form rejects an invalid phone before hitting the API.
- Rejecting the order in admin restores stock.
- Optional account flow (register/login/account orders) if implemented.