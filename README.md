# BD Courier

A WooCommerce plugin for shops in Bangladesh that deliver with **Pathao** and **Steadfast**. Send orders to either courier from your WordPress dashboard, follow every parcel, print invoices and labels, and see how each courier is performing — all in one place.

Pathao and Steadfast stay completely separate: their own credentials, settings, buttons and labels everywhere. Nothing is ever sent to a courier automatically; every parcel is sent by a person clicking a button.

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Daily use](#daily-use)
- [Order statuses](#order-statuses)
- [Customers](#customers)
- [Settings reference](#settings-reference)
- [Moving from the official Pathao or Steadfast plugin](#moving-from-the-official-pathao-or-steadfast-plugin)
- [Good to know](#good-to-know)
- [Troubleshooting](#troubleshooting)

---

## Features

**Sending parcels**
- "Pathao" and "Steadfast" buttons on every order in **WooCommerce → Orders**.
- A review popup before anything is sent, prefilled from the order and fully editable.
- Bulk send: tick many orders, review them in one table, and send them one by one with a result for each.
- Clear errors shown next to the field that needs fixing (for example a wrong phone number).
- The same order can never be sent to both couriers.

**Following parcels**
- Parcel status updates arrive automatically from Pathao and Steadfast (webhooks).
- A scheduled check catches any update that was missed.
- The WooCommerce order status follows the parcel: Sent to Courier → In Transit → Delivered, and so on.
- A **Shipments** page listing every parcel from both couriers, with search and filters.

**Managing orders**
- A **Confirmed** order status for orders your team has checked with the customer.
- A quick view (the eye icon) with every status as one-click buttons, the courier summary, and private notes.
- Private team notes; the newest one is filled in as the rider note when sending.
- The customer's phone number under their name in the Orders list, and search by phone number or consignment ID.
- Edit a parcel after sending (see [Good to know](#good-to-know)).

**More**
- Customer record check ("fraud score"): how often this phone number accepted deliveries, per courier and in your own shop.
- Printable A4 invoice and 100 × 150 mm parcel label, one order or many at once.
- Reports per courier: parcels, delivery success, cash collected, cash still out, delivery fees, with CSV export.
- Delivery details and a tracking link for customers on their order page and in WooCommerce emails.
- Branding: your own name, colour and logo in the dashboard and on invoices.

---

## Requirements

- WordPress 6.4 or newer
- WooCommerce (active)
- PHP 7.4 or newer
- A Pathao merchant account and/or a Steadfast merchant account, with API access

Works with both WooCommerce order storage types (classic and High-Performance Order Storage).

---

## Installation

1. On this page, click **Code → Download ZIP**.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the ZIP and click **Install Now**.
3. Click **Activate**.

GitHub names the folder inside the ZIP `bd-courier-main`. That works as it is; you can rename it to `bd-courier` before zipping if you prefer.

A new **BD Courier** menu appears in the dashboard with three pages: **Shipments**, **Reports** and **Settings**.

> If the official Pathao or Steadfast plugin is installed, see [Moving from the official plugin](#moving-from-the-official-pathao-or-steadfast-plugin) before deactivating it.

---

## Setup

Open **BD Courier → Settings**. Connect at least one courier; you can use one or both.

### Connect Pathao

1. In the [Pathao merchant panel](https://merchant.pathao.com/courier/developer-api), open **Developer API** and copy your **Client ID** and **Client Secret**.
2. In **BD Courier → Settings → Pathao**:
   - Choose **Environment**: *Sandbox* for testing, *Live* for real parcels.
   - Paste the Client ID and Client Secret.
3. Click **Save and test connection**. You should see "Pathao is connected and ready to use", and your pickup stores appear under **Pickup store**.
4. Choose your default **Pickup store**, **Delivery type** and **Item type**, and save. These are only starting values; you can change them for each parcel.

### Connect Steadfast

1. Get your **API Key** and **Secret Key** from your Steadfast merchant panel (or from Steadfast support).
2. In **BD Courier → Settings → Steadfast**, paste both keys.
3. Click **Save and test connection**. You should see "Steadfast is connected and ready to use".
4. Choose the default **Delivery type** and save.

### Turn on automatic status updates (webhooks)

So that parcel statuses update by themselves, tell each courier where to send updates.

**Pathao** — in **Settings → Pathao → Automatic status updates**, copy the two values into the **Webhook** section of the Pathao merchant panel:

| In Pathao | Copy from BD Courier |
|---|---|
| Callback URL | `https://your-site.com/wp-json/bd-courier/v1/pathao/webhook` |
| Secret | The **Secret** shown in BD Courier |

**Steadfast** — in **Settings → Steadfast → Automatic status updates**, copy the two values into the **Webhook** section of the Steadfast merchant panel:

| In Steadfast | Copy from BD Courier |
|---|---|
| Callback URL | `https://your-site.com/wp-json/bd-courier/v1/steadfast/webhook` |
| Auth token (Bearer) | The **Auth token** shown in BD Courier |

Each courier tab shows "Last update received … ago" once updates start arriving. Your site must be reachable from the internet (webhooks don't work on a local test site).

### Make it yours (optional)

In **Settings → Branding**: set the name shown in the menu, an accent colour, your logo, and the shop name, phone, address and footer line used on invoices and labels.

---

## Daily use

### Send one order

1. Go to **WooCommerce → Orders**.
2. In the **Courier** column, click **Pathao** or **Steadfast** on the order.
3. Check the details in the popup:
   - **Customer**: name, phone, second phone, delivery address. For Pathao you can also choose city, zone and area, or leave them empty and Pathao works them out from the address.
   - **Parcel**: cash to collect, weight, quantity, item description, note for the rider, and delivery type.
   - The customer's delivery record is shown at the top; click **Check customer record** to look it up.
4. Click **Send with Pathao** / **Send with Steadfast**.

The Courier column then shows the courier, consignment ID, status, cash to collect and fee, with small buttons to **refresh**, **edit**, **track** and **print**.

**Cash to collect** is filled in for you: the order total for cash-on-delivery orders, and 0 for orders already paid online.

### Send many orders at once

1. In **WooCommerce → Orders**, tick the orders.
2. From **Bulk actions**, choose **Send with Pathao** or **Send with Steadfast**, then **Apply**.
3. A review table opens. Orders already sent or cancelled are listed at the top and left out.
4. Set the options shared by every parcel at the top (pickup store, delivery type), and edit any row.
5. Click **Send N parcels**. Each row shows **Sent** with its ID, or the error in red.
6. Fix any failed rows and click **Try again** — only those are re-sent.

Keep the popup open until it finishes. Up to 50 orders per bulk send.

### Quick view (eye icon)

Click the eye icon next to an order in the Orders list to:
- change the order status with one click,
- send the order with Pathao or Steadfast, or see its parcel,
- read and add **private notes**.

### Private notes

Private notes are WooCommerce's own private order notes — only your team sees them. Add one from the quick view (or the Order notes box on the order page). The **newest** private note is filled in automatically as the **note for the rider** when you send the order, and you can still edit it before sending.

### Edit a parcel after sending

Click the **edit** (pencil) button on a sent parcel. Neither courier accepts changes from outside its own panel, so:
1. Your changes are saved in your dashboard and the parcel is marked **"Edited here, not on Pathao/Steadfast yet"**.
2. Make the same change in the courier's merchant panel.
3. Click **Mark as updated**.

Delivered, returned and cancelled parcels can't be edited.

### Shipments page

**BD Courier → Shipments** lists every parcel from both couriers:
- Tabs for **All couriers**, **Pathao** and **Steadfast**.
- Status filters with counts (In Transit, Delivered, Edited not updated, …).
- Search by order number, consignment ID, customer name or phone; filter by the date sent.
- **Refresh statuses** asks the couriers for the latest status of every unfinished parcel on the page.
- **Print this page** prints invoices or labels for every parcel shown.

### Print invoices and labels

- One order: the **print** button on a sent parcel.
- Many orders: tick them in the Orders list and choose **Print invoices / labels** from **Bulk actions**, or use **Print this page** on Shipments.

The print page lets you switch between the **Invoice** (A4) and the **Parcel label** (100 × 150 mm, black and white for thermal printers), then click **Print**. Each order prints on its own page.

### Reports

**BD Courier → Reports** shows, for any period (today, last 7 or 30 days, this or last month, or your own dates):
- parcels sent, delivery success, cash collected, cash still out and delivery fees;
- a card per courier with where its parcels are now;
- a side-by-side table and a parcels-per-day chart;
- **Download CSV** with one row per parcel.

Parcels are counted by the date they were sent. Delivery success only counts parcels that have finished.

### Customer record check

Click **Check customer** under an order's send buttons (or inside the send popup). You'll see, per courier, how many parcels this phone number received and refused, plus their order history in your shop, and an overall reading: *Good record*, *Be careful*, *High risk* or *New customer*. Results are kept for 24 hours, because the couriers limit these lookups.

---

## Order statuses

BD Courier adds these WooCommerce order statuses:

| Status | Meaning |
|---|---|
| **Confirmed** | Set by your team after checking the order with the customer |
| Sent to Courier | The parcel was created at Pathao or Steadfast |
| Picked Up | The courier collected the parcel |
| In Transit | On its way |
| Out for Delivery | With the rider |
| Delivered | Delivered to the customer (counts as paid) |
| Partial Delivery | The customer kept part of the order (counts as paid) |
| On Hold at Courier | Delayed or a delivery attempt failed |
| Returned | Coming back to you |
| Courier Cancelled | Cancelled at the courier |

When **Follow the parcel status** is on (the default), the order moves between these automatically as the courier reports progress. Every change adds an order note.

The Orders **Bulk actions** menu offers "Change status to…" for **Confirmed**, **Delivered** and **Returned** by default. Choose which appear in **Settings → General → Bulk status changes**. Every status is always available in the quick view.

---

## Customers

When **Settings → General → Customers → Tracking** is on (the default), customers see a **Delivery** box with the courier, tracking ID, status and a **Track** link:
- on their order page in **My Account** and on the order-received page,
- in the order emails WooCommerce already sends.

There is also an optional email, **"Order sent with courier"**, sent when you send their parcel. It is **off by default** — switch it on and edit it in **WooCommerce → Settings → Emails**.

---

## Settings reference

**General**
- **Order status** — follow the parcel status (on by default).
- **Bulk status changes** — which statuses appear in the Orders bulk menu.
- **Default weight** — used when products have no weight (0.5 kg by default).
- **Check automatically** — how often to re-check unfinished parcels (off, every 30 minutes, hourly, every 3 or 6 hours). **Check now** runs it immediately.
- **Customers → Tracking** — show delivery details to customers.

**Branding** — dashboard name, accent colour, logo, and shop details for invoices and labels.

**Pathao** — environment, Client ID and Secret, webhook details, default store, delivery type and item type.

**Steadfast** — API Key and Secret Key, webhook details, default delivery type, and whether to use the customer's checkout note when there is no private note.

Saved secrets are never shown again; leave the field empty to keep the saved one.

---

## Moving from the official Pathao or Steadfast plugin

BD Courier reads parcels created by the official Pathao and Steadfast plugins, so orders you already sent still show their consignment ID and status in the Courier column. To switch:

1. Install and activate BD Courier.
2. Connect your couriers in **BD Courier → Settings** (the credentials are the same).
3. Update the webhook addresses in each merchant panel to the BD Courier ones.
4. Deactivate the official plugin(s).

Parcels from the official plugins appear in the Orders list but not on the Shipments page or in Reports.

---

## Good to know

- **Nothing is sent automatically.** Every parcel is sent by a person clicking a button. The scheduled check only reads statuses.
- **Editing a sent parcel** is saved in your dashboard and flagged; it is not sent to the courier, because neither courier's API accepts changes. Make the same change in the courier's panel.
- **Bulk send** runs in the open popup. If the browser tab is closed part-way, the remaining orders are simply not sent and still show their send buttons.
- **Pathao city and zone** are chosen by Pathao from the address in bulk send; send an order on its own to choose them yourself.
- **The scheduled check** uses WordPress's background tasks (WP-Cron). On a very quiet site, checks can run late.
- **Steadfast has no sandbox**, so test sends create real parcels — cancel them in the Steadfast panel.
- **Not yet confirmed against live accounts:** the Pathao status refresh, the Pathao and Steadfast customer-record lookups, and the two tracking links. If one of these doesn't work with your account, please open an issue with the message you see.

---

## Troubleshooting

**"Pathao did not accept the Client ID and Client Secret"**
Check both values, and that **Environment** matches them (sandbox credentials only work with *Sandbox*).

**"Steadfast did not accept the API Key and Secret Key"**
Re-copy both keys from the Steadfast panel, with no spaces before or after.

**Statuses don't update by themselves**
- Check the webhook URL and secret in the courier's panel match the ones in BD Courier exactly.
- Look for "Last update received" on the courier's settings tab.
- Make sure your site is reachable from the internet and a security plugin isn't blocking `/wp-json/`.
- Meanwhile, **Check now** (Settings → General) or **Refresh statuses** (Shipments) fetch the latest status.

**The Pathao/Steadfast buttons don't appear on an order**
The courier isn't connected yet, or the order is cancelled, refunded or failed.

**"This order has already been sent with …"**
An order can only have one parcel. Open it in the courier's merchant panel to make changes.

**Printing looks wrong**
In your browser's print dialog, choose the matching paper size (A4 for invoices, 100 × 150 mm for labels), set margins to *Default* or *None*, and turn on *Background graphics*.
