# Twinbackend Manual Documentation

**Version:** 2.0
**Backend:** TwinBackend (Laravel 11)  
**Base URL:** `https://telcovantage.com/api/v1`

---

## Base URL Setup (Frontend Constant)

Set this once in your app config:

```js
// ✅ Online — production domain
const BASE_URL = 'https://telcovantage.com/api/v1'

// Local Wi-Fi only — use this when device is on the same network as the server
// const BASE_URL = 'http://192.168.1.17:8080/api/v1'
```

All endpoint paths in this document are relative to `BASE_URL`.  
Example: `GET /asbuilt/sites` → `GET https://telcovantage.com/api/v1/asbuilt/sites`

---

## Authentication

| Header | Value | Required |
|--------|-------|----------|
| `X-AsBuilt-Key` | `asbuilt-iq-secret-key-2026` | ✅ Always |
| `ngrok-skip-browser-warning` | `1` | ❌ Only needed when using ngrok URL |

No user login required. API key only.

---

## Terminology

| AsBuilt IQ | Backend Table / Column |
|------------|----------------------|
| **Site / Area** | `skycable_areas` (NCR, North Luzon, South Luzon, Visayas, Mindanao) |
| **Node Identifier** | `skycable_nodes.node_id` — VARCHAR string, e.g. `"TY1401"` |
| **Node Name** | `skycable_nodes.name` — Village Address If ano ung address, e.g. `"MONTEVISTA SUBD."` |
| **Pole** | `poles` + `skycable_poles` |
| **pole_index** | `skycable_poles.pole_index` — unique 1-based counter per pole within a node, assigned by AsBuilt IQ |
| **Span** | `skycable_spans` + `skycable_span_summaries` |

> `sequence` is **NOT used by AsBuilt IQ**. It is reserved for the lineman mobile app (teardown order). Never send `sequence` in import payloads.

---

## How It Works

```
1. Fetch all sites / areas
         GET /asbuilt/sites
         → [ NCR, North Luzon, South Luzon, Visayas, Mindanao ]

2. Display all areas on the screen using forEach

3. User selects a site / area
         GET /asbuilt/sites/{areaId}/nodes
         → [ { node_id: "TY1401", name: "MONTEVISTA SUBD." }, ... ]

4. User can choose either:
         Option A: Select an existing node
         Option B: Add node manually

5. If user selects an existing node:
         Use selected node_id and name

6. If user adds node manually:
         Display a form input for node_id, node_name, region, province, city,
         and barangay_name

7. User uploads poles and spans

8. On POST:
         All poles and spans are posted to the selected/manual node_id
         Every pole MUST have a unique pole_index (1, 2, 3 …)
         Every span MUST reference from_pole_index and to_pole_index

9. After POST:
         Display all uploaded poles and spans under that specific node
```

---

## ForEach Pattern

```js
const BASE_URL = 'https://telcovantage.com/api/v1'
const API_KEY  = 'asbuilt-iq-secret-key-2026'
const headers  = {
  'X-AsBuilt-Key': API_KEY
}

// Step 1 — Load all sites / areas
const sitesRes = await fetch(`${BASE_URL}/asbuilt/sites`, { headers })
const areas    = await sitesRes.json()

// Step 2 — Display every area
areas.forEach(area => {
  displayAreaCard({
    id: area.id,
    name: area.name,
    node_count: area.node_count
  })
})

// Step 3 — User picks an area
const selectedArea = area

// Step 4 — Load all nodes under selected area
const nodesRes     = await fetch(`${BASE_URL}/asbuilt/sites/${selectedArea.id}/nodes`, { headers })
const { nodes }    = await nodesRes.json()

// Step 5 — Display every existing node
nodes.forEach(node => {
  displayNodeOption({
    id: node.id,
    node_id: node.node_id,
    name: node.name,
    pole_count: node.pole_count,
    source_file: node.source_file
  })
})
```

---

## Endpoints

---

### 1. List Sites / Areas

```
GET /api/v1/asbuilt/sites
```

Returns all areas. These are the **Sites** in AsBuilt IQ.

**Response `200`:**

```json
[
  { "id": 1, "name": "NCR",         "node_count": 12 },
  { "id": 2, "name": "North Luzon", "node_count": 8  },
  { "id": 3, "name": "South Luzon", "node_count": 6  },
  { "id": 4, "name": "Visayas",     "node_count": 4  },
  { "id": 5, "name": "Mindanao",    "node_count": 3  }
]
```

---

### 2. List Nodes by Site / Area

```
GET /api/v1/asbuilt/sites/{areaId}/nodes
```

Returns all nodes under the selected area.

**Response `200`:**

```json
{
  "site": {
    "id":   1,
    "name": "NCR"
  },
  "nodes": [
    {
      "id":               10,
      "node_id":          "TY1401",
      "name":             "MONTEVISTA SUBD.",
      "full_label":       "MONTEVISTA SUBD.",
      "status":           "pending",
      "report_type":      null,
      "source_file":      null,
      "pole_count":       0,
      "subcontractor_id": null,
      "subcontractor":    null,
      "team_id":          null,
      "team":             null
    },
    {
      "id":               11,
      "node_id":          "TY1402",
      "name":             "BRGY. DILA",
      "full_label":       "BRGY. DILA",
      "status":           "in_progress",
      "report_type":      "full_report",
      "source_file":      "asbuilt",
      "pole_count":       15,
      "subcontractor_id": 3,
      "subcontractor":    "ABC Subcon",
      "team_id":          7,
      "team":             "Team Alpha"
    }
  ]
}
```

| Field | Description |
|-------|-------------|
| `id` | Integer database ID of the node |
| `node_id` | **VARCHAR string identifier** e.g. `"TY1401"` — use this in the import payload |
| `name` | Node name saved in `skycable_nodes.name` |
| `source_file` | `"asbuilt"` if previously imported via AsBuilt IQ |
| `pole_count` | Number of poles already enrolled in this node |
| `subcontractor_id` | Integer ID of assigned subcontractor, or `null` |
| `subcontractor` | Name of assigned subcontractor, or `null` |
| `team_id` | Integer ID of assigned team, or `null` |
| `team` | Name of assigned team, or `null` |

---

### 3. Import JSON Body

```
POST /api/v1/asbuilt/import
Content-Type: application/json
```

Use this endpoint to import a **new** node and its poles/spans.

If the `node_id` does not exist yet inside the selected `area_id`, the backend creates the node and imports its poles and spans.  
If the `node_id` **already exists** inside the selected `area_id`, the import is **rejected with `422`** — re-uploading the same node_id is not allowed. To import again you must change the `node_id` first.

```json
{
  "message": "Import failed. This node id (LP-1123) already exists on the backend. Please double check properly to avoid node id duplication."
}
```

> The duplicate check is **case-insensitive** and scoped to the `area_id` — `lp-1123` and `LP-1123` in the same area count as the same node. The same node_id may still be used in a *different* area.

---

## Import Payload Structure

### Required Fields (root level)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `node_id` | string | ✅ | VARCHAR identifier e.g. `"TY1401"` — maps to `skycable_nodes.node_id` |
| `node_name` | string | ✅ | Saved to `skycable_nodes.name` |
| `area_id` | integer | ✅ | From `GET /asbuilt/sites` |
| `region` | string | ❌ | e.g. `"CALABARZON"` — saved to node |
| `province` | string | ❌ | e.g. `"LAGUNA"` — saved to node |
| `city` | string | ❌ | e.g. `"STA. ROSA"` — saved to node |
| `barangay_name` | string | ❌ | e.g. `"Balibago"` — saved to node directly. Not per-pole. |
| `subcontractor_id` | integer | ❌ | ID from `GET /asbuilt/subcontractors` — assigns subcontractor to node |
| `team_id` | integer | ❌ | ID from `GET /asbuilt/teams` — assigns team to node |
| `poles` | array | ✅ | Minimum 1 pole required |
| `spans` | array | ❌ | Optional. Empty array or omit if no spans |

### Pole Fields (`poles[]`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `pole_index` | integer | ✅ **Required** | 1-based sequential counter per pole within the node. Used by spans to identify exact poles. |
| `pole_code` | string | ✅ | Pole tag label from the canvas. Stored UPPERCASE. e.g. `"PL-001"`, `"NPT"` |
| `latitude` | decimal | ✅ **Required** | GPS latitude of the pole. −90 to 90 |
| `longitude` | decimal | ✅ **Required** | GPS longitude of the pole. −180 to 180 |

> `pole_index` is the **count identifier** for each pole. It must start at 1 and increment by 1 for each pole in the node. Never send `sequence` — that field is controlled by the lineman mobile app.

### Span Fields (`spans[]`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_pole_code` | string | ✅ | `pole_code` of the starting pole. **Descriptive only — ignored for connecting the span.** |
| `to_pole_code` | string | ✅ | `pole_code` of the ending pole. **Descriptive only — ignored for connecting the span.** |
| `from_pole_index` | integer | ✅ **Required** | `pole_index` of the starting pole — **the sole key** used to connect the span. Must match a `pole_index` in the `poles` array |
| `to_pole_index` | integer | ✅ **Required** | `pole_index` of the ending pole — **the sole key** used to connect the span. Must match a `pole_index` in the `poles` array |
| `strand_length` | decimal | ✅ **Required** | Length in meters. e.g. `50.5` |
| `number_of_runs` | integer | ✅ **Required** | Minimum 1. `expected_cable = strand_length × number_of_runs` — required collection by lineman |
| `components` | object | ❌ | Equipment counts. All default to `0` if omitted |
| `components.node` | integer | ❌ | Node count. Min 0 |
| `components.amplifier` | integer | ❌ | Amplifier count. Min 0 |
| `components.extender` | integer | ❌ | Extender count. Min 0 |
| `components.tsc` | integer | ❌ | TSC count. Min 0 |
| `components.powersupply` | integer | ❌ | Power supply count. Min 0 |
| `components.ps_housing` | integer | ❌ | PS housing count. Min 0 |

> `expected_cable` is auto-computed: `strand_length × number_of_runs` and saved to `skycable_span_summaries.expected_cable`.

---

### How `pole_index` Works

Every pole in the `poles` array gets a unique 1-based counter as its `pole_index`. Every span then references exactly two poles using `from_pole_index` and `to_pole_index`.

```
poles:
  pole_index: 1  →  PL-001
  pole_index: 2  →  PL-002
  pole_index: 3  →  PL-003

spans:
  from_pole_index: 1 → to_pole_index: 2   ✅ Pole 1 to Pole 2
  from_pole_index: 2 → to_pole_index: 3   ✅ Pole 2 to Pole 3
```

This also solves duplicate `pole_code` cases — two poles can share the same code but have different indexes. **Each `pole_index` is stored as its own pole record with its own coordinates**, so two `NPT` poles render as two distinct pins and the span between them connects correctly:

```
poles:
  pole_index: 1  →  NPT  (lat 14.5397, lng 121.1092)   ← own record, own coords
  pole_index: 2  →  NPT  (lat 14.5401, lng 121.1098)   ← same code, different record + location
  pole_index: 3  →  PL-002

spans:
  from_pole_index: 1 → to_pole_index: 2   ✅ correct — index is unambiguous (NPT → NPT works)
  from_pole_index: 2 → to_pole_index: 3   ✅ correct
```

> **Missing `pole_index` = `422` error. The import will not proceed.**  
> There is no fallback matching. `pole_index` is the only way the backend connects spans to poles — `pole_code` is never used to resolve a span, so duplicate names are fully supported.

---

## Full Import Example

```json
{
  "node_id":   "TY1401",
  "node_name": "MONTEVISTA SUBD.",
  "area_id":   1,
  "region":    "CALABARZON",
  "province":  "LAGUNA",
  "city":      "STA. ROSA",
  "poles": [
    {
      "pole_index":    1,
      "pole_code":     "PL-001",
      "latitude":      14.539770,
      "longitude":     121.109219
    },
    {
      "pole_index":    2,
      "pole_code":     "PL-002",
      "latitude":      14.540100,
      "longitude":     121.109800
    },
    {
      "pole_index":    3,
      "pole_code":     "PL-003",
      "latitude":      14.540750,
      "longitude":     121.110300
    }
  ],
  "spans": [
    {
      "from_pole_code":  "PL-001",
      "to_pole_code":    "PL-002",
      "from_pole_index": 1,
      "to_pole_index":   2,
      "strand_length":   50.5,
      "number_of_runs":  1,
      "components": {
        "node":        2,
        "amplifier":   1,
        "extender":    0,
        "tsc":         1,
        "powersupply": 0,
        "ps_housing":  0
      }
    },
    {
      "from_pole_code":  "PL-002",
      "to_pole_code":    "PL-003",
      "from_pole_index": 2,
      "to_pole_index":   3,
      "strand_length":   45.0,
      "number_of_runs":  2,
      "components": {
        "node":        1,
        "amplifier":   0,
        "extender":    1,
        "tsc":         0,
        "powersupply": 1,
        "ps_housing":  1
      }
    }
  ]
}
```

**Result of this import:**

- `PL-001`: `pole_index = 1`
- `PL-002`: `pole_index = 2`
- `PL-003`: `pole_index = 3`
- Span 1 `expected_cable` = `50.5 × 1 = 50.5`
- Span 2 `expected_cable` = `45.0 × 2 = 90.0`

---

## Import — Response `201`

```json
{
  "message": "AsBuilt import completed.",
  "data": {
    "node": {
      "id":           10,
      "node_id":      "TY1401",
      "name":         "MONTEVISTA SUBD.",
      "region":       "CALABARZON",
      "province":     "LAGUNA",
      "city":         "STA. ROSA",
      "barangay_name":"Balibago",
      "report_type":  "full_report",
      "source_file":  "asbuilt"
    },
    "poles_created": ["PL-001", "PL-002", "PL-003"],
    "poles_updated": [],
    "spans_created": ["SRC-0001", "SRC-0002"],
    "spans_updated": [],
    "total_poles":   3,
    "total_spans":   2,
    "errors":        []
  }
}
```

> **Note:** If `from_pole_index` or `to_pole_index` of a span does not match any pole in the `poles` list, that span is skipped and an error message is added to `errors[]`. Other spans still save successfully.

---

### 4. Import File Upload

```
POST /api/v1/asbuilt/import
Content-Type: multipart/form-data
```

Upload the export as a `.json` file. The file must contain the same structure as the JSON body above.

| Field | Type | Description |
|-------|------|-------------|
| `file` | `.json` file | Must include all required fields: `node_id`, `node_name`, `area_id`, `poles`, `spans` |

Accepted MIME types: `application/json`, `text/plain`, `text/json`

---

### 5. Verify Node State

```
GET /api/v1/asbuilt/node/{nodeId}
```

Check what was imported. `{nodeId}` is the **integer database ID** from `skycable_nodes.id` — use `data.node.id` from the import response.

**Response `200`:**

```json
{
  "node": {
    "id":               10,
    "node_id":          "TY1401",
    "name":             "MONTEVISTA SUBD.",
    "area":             "NCR",
    "region":           "CALABARZON",
    "province":         "LAGUNA",
    "city":             "STA. ROSA",
    "barangay":         "Balibago",
    "report_type":      "full_report",
    "source_file":      "asbuilt",
    "status":           "pending",
    "subcontractor_id": 3,
    "subcontractor":    "ABC Subcon",
    "team_id":          7,
    "team":             "Team Alpha"
  },
  "component_totals": {
    "node":        5,
    "amplifier":   10,
    "extender":    3,
    "tsc":         2,
    "powersupply": 1,
    "ps_housing":  1,
    "cable":       285.5
  },
  "poles": [
    {
      "skycable_pole_id": 1,
      "pole_id":          1,
      "pole_code":        "PL-001",
      "pole_index":       1,
      "latitude":         14.539770,
      "longitude":        121.109219,
      "status":           "pending",
      "date_start":       null,
      "finished_at":      null,
      "duration":         null
    }
  ],
  "spans": [
    {
      "span_id":         1,
      "span_code":       "LPC-0001",
      "from_pole_code":  "PL-001",
      "from_pole_index": 1,
      "to_pole_code":    "PL-002",
      "to_pole_index":   2,
      "strand_length":   50.5,
      "number_of_runs":  1,
      "expected_cable":  50.5,
      "status":          "pending",
      "components": {
        "node":        2,
        "amplifier":   1,
        "extender":    0,
        "tsc":         1,
        "powersupply": 0,
        "ps_housing":  0
      }
    }
  ]
}
```

> The `pole_index` field in each pole is the AsBuilt-assigned count (1, 2, 3 …). `sequence` is not returned — it belongs to the lineman teardown workflow.
> The node's barangay is returned as `"barangay"` (not `"barangay_name"`) in this endpoint.
> Each span returns `span_code` — auto-generated unique code based on node city initials + sequential number.
> `component_totals` is the sum of all expected components across every span in the node — use this for the node-level summary card.
> `subcontractor` and `team` are the resolved names. Both are `null` if not yet assigned.

---

## Span Code — Auto-Generation

Every span gets a unique `span_code` automatically generated by the backend during import. **No need to send it in the payload.**

### Format

```
[CITY_INITIALS]-[SEQUENTIAL_NUMBER]
```

### Examples

| Node City | Initials | Span Codes |
|---|---|---|
| Las Pinas City | `LPC` | `LPC-0001`, `LPC-0002`, `LPC-0003` … |
| Pasig | `P` → uses `PSG` fallback | `P-0001`, `P-0002` … |
| Taguig City | `TC` | `TC-0001`, `TC-0002` … |
| Caloocan City | `CC` | `CC-0001`, `CC-0002` … |
| Quezon City | `QC` | `QC-0001`, `QC-0002` … |

### Rules

- Generated from **first letter of each word** in the node's `city` field
- Sequential **globally** across all spans with the same city prefix — prevents unique constraint collision
- Existing spans that already have a `span_code` keep their code on re-import
- New spans added later continue from the highest existing number

### How Components Map to Span Codes

The `components` object in each span tells you exactly what equipment is expected on that specific span:

```
Node: Las Pinas Demo  (city: Las Pinas City)
├── LPC-0001  PL-001 → PL-002   amplifier: 2, node: 1  ← 2 amplifiers expected here
├── LPC-0002  PL-002 → PL-003   amplifier: 0, tsc: 1
└── LPC-0003  PL-003 → PL-004   amplifier: 1, powersupply: 1

Total amplifiers in node = 3
  LPC-0001 has 2 → lineman collects 2 here
  LPC-0003 has 1 → lineman collects 1 here
```

So when a lineman does teardown on `LPC-0001`, they know to collect **2 amplifiers** on that specific span. The `span_code` is the unique identifier shown on their mobile app.

---

## Manual Node Creation Flow

### Step 1 — Get All Areas

```
GET /api/v1/asbuilt/sites
```

Display all areas on the screen.

```js
const sitesRes = await fetch(`${BASE_URL}/asbuilt/sites`, { headers })
const areas    = await sitesRes.json()

areas.forEach(area => {
  displayAreaCard({
    id: area.id,
    name: area.name,
    node_count: area.node_count
  })
})
```

---

### Step 2 — User Clicks an Area

After the user clicks one area, get all existing nodes under that area.

```
GET /api/v1/asbuilt/sites/{areaId}/nodes
```

```js
const selectedArea = area

const response  = await fetch(`${BASE_URL}/asbuilt/sites/${selectedArea.id}/nodes`, { headers })
const { nodes } = await response.json()

nodes.forEach(node => {
  displayNodeOption({
    id: node.id,
    node_id: node.node_id,
    name: node.name,
    pole_count: node.pole_count,
    source_file: node.source_file
  })
})
```

---

### Step 3 — Show Two Options

After selecting an area, the user must have two options:

```
Option 1: Select Existing Node
Option 2: Add Node Manually
```

---

## Option 1 — Select Existing Node

If the user clicks an existing node, save the selected node data.

```js
const selectedNode = {
  id: node.id,
  node_id: node.node_id,
  name: node.name
}
```

This selected `node_id` will be used when posting poles and spans.

---

## Option 2 — Add Node Manually

If the user clicks **Add Node Manually**, display a form input.

### Manual Node Form Fields

```
Node ID       (required)
Node Name     (required)
Region        (optional)
Province      (optional)
City          (optional)
Barangay Name (optional)
```

Example form value:

```json
{
  "node_id":      "TY1501",
  "node_name":    "BRGY. BALIBAGO NODE",
  "region":       "CALABARZON",
  "province":     "LAGUNA",
  "city":         "STA. ROSA",
  "barangay_name":"Balibago"
}
```

Frontend form state:

```js
const manualNode = {
  node_id:      form.node_id,
  name:         form.node_name,
  region:       form.region,
  province:     form.province,
  city:         form.city,
  barangay_name:form.barangay_name
}
```

> Manual node creation uses the same `POST /asbuilt/import` endpoint.  
> If the `node_id` does not exist yet inside the selected `area_id`, the backend creates the node automatically.  
> If the `node_id` already exists inside the selected `area_id`, the import is **rejected with `422`** — change the `node_id` before posting again.

---

## Post Data After Selecting or Creating a Node

After the user selects an existing node or creates a node manually, the user can click **Post**.

When they click **Post**, all uploaded poles and spans must be posted to that specific `node_id`.

```http
POST /api/v1/asbuilt/import
Content-Type: application/json
```

### Frontend Payload Builder

```js
// ── API config ────────────────────────────────────────────────────────────────
const BASE_URL = 'https://telcovantage.com/api/v1'
const API_KEY  = 'asbuilt-iq-secret-key-2026'

const headers = {
  'Content-Type': 'application/json',
  'X-AsBuilt-Key': API_KEY
}

// ── Build and send payload ────────────────────────────────────────────────────
const targetNode = selectedNode || manualNode

const payload = {
  node_id:   targetNode.node_id,
  node_name: targetNode.name,
  area_id:   selectedArea.id,

  // Optional node location fields
  region:   manualNode?.region   || selectedArea.region   || '',
  province: manualNode?.province || selectedArea.province || '',
  city:     manualNode?.city     || selectedArea.city     || '',

  // pole_index MUST be a unique 1-based integer for each pole — this is the count identifier
  // Never include sequence — that is for the lineman app only
  poles: uploadedPoles.map((pole, i) => ({
    pole_index:    i + 1,               // 1-based count: 1, 2, 3 …
    pole_code:     pole.pole_code,
    latitude:      pole.latitude  ?? null,
    longitude:     pole.longitude ?? null,
  })),

  spans: uploadedSpans.map(span => ({
    from_pole_code:  span.from_pole_code,
    to_pole_code:    span.to_pole_code,
    from_pole_index: span.from_pole_index,  // required — must match a pole_index above
    to_pole_index:   span.to_pole_index,    // required — must match a pole_index above
    strand_length:   span.strand_length,   // required — actual cable length in meters
    number_of_runs:  span.number_of_runs,  // required — actual runs counted by lineman
    components: {
      node:        span.components?.node        ?? 0,
      amplifier:   span.components?.amplifier   ?? 0,
      extender:    span.components?.extender    ?? 0,
      tsc:         span.components?.tsc         ?? 0,
      powersupply: span.components?.powersupply ?? 0,
      ps_housing:  span.components?.ps_housing  ?? 0
    }
  }))
}

const res = await fetch(`${BASE_URL}/asbuilt/import`, {
  method:  'POST',
  headers: headers,
  body:    JSON.stringify(payload)
})
const importResponse = await res.json()
// importResponse.data.node.id = integer DB id — use for verify call
console.log('Import result:', importResponse)
```

---

## Display Uploaded Poles and Spans After Post

After successful import, use the integer database node ID from the import response.

```js
const nodeDatabaseId = importResponse.data.node.id
```

Then verify and display the uploaded data.

```http
GET /api/v1/asbuilt/node/{nodeId}
```

```js
const verifyRes = await fetch(`${BASE_URL}/asbuilt/node/${nodeDatabaseId}`, {
  headers: { 'X-AsBuilt-Key': API_KEY }
})
const nodeState = await verifyRes.json()

displayNodeDetails(nodeState.node)
displayPoles(nodeState.poles)   // each pole has pole_index (1, 2, 3 …)
displaySpans(nodeState.spans)
```

---

## Final UI Flow

```
1.  GET all areas
2.  Display all areas using forEach
3.  User clicks an area
4.  GET all nodes under selected area
5.  Display existing nodes
6.  User chooses either:
       A. Select existing node
       B. Add node manually
7.  If manual, display node form:
       - node_id        (required)
       - node_name      (required)
       - region         (optional)
       - province       (optional)
       - city           (optional)
       - barangay_name  (optional)
8.  User uploads poles and spans
9.  Each pole MUST have pole_index (1-based count: 1, 2, 3 …)
    Each span MUST reference from_pole_index and to_pole_index
10. User clicks Post
11. POST data to /asbuilt/import using the selected/manual node_id
12. Backend creates or updates the node
13. Backend saves poles (with pole_index) and spans under that specific node_id
14. expected_cable per span is auto-computed: strand_length × number_of_runs
16. GET /asbuilt/node/{nodeId}
17. Display all uploaded poles (pole_index = count) and spans under that node
```

---

## All Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/asbuilt/sites` | List all areas |
| `GET` | `/asbuilt/sites/{areaId}/nodes` | List nodes under an area (includes subcontractor + team names) |
| `POST` | `/asbuilt/import` | Bulk import — JSON body or `.json` file upload |
| `POST` | `/asbuilt/import-by-sequence` | Index-based import — spans use `from_pole_index`/`to_pole_index` with string pole identifiers |
| `GET` | `/asbuilt/node/{nodeId}` | Verify node state after import (includes subcontractor, team, component_totals) |
| `GET` | `/asbuilt/subcontractors` | List all subcontractors — use IDs for `subcontractor_id` in import |
| `GET` | `/asbuilt/teams?subcontractor_id={id}` | List teams — filter by subcontractor |
| `POST` | `/asbuilt/backfill-span-codes` | One-time utility — generate `span_code` for all existing spans that have none |

---

## Subcontractor & Team Assignment

`subcontractor_id` and `team_id` are optional fields in the import payload. Assign them before posting so linemen can be tracked per node without going back to the web dashboard.

**UI flow:**

```
1. After user selects a Site and Node (or creates one manually)
   — show a subcontractor + team assignment step BEFORE the Post button
2. Load all subcontractors → forEach → display as dropdown or card list
3. User selects a subcontractor → load teams under it → forEach → display
4. User selects a team
5. Include subcontractor_id and team_id in the import payload
```

---

### 8. List Subcontractors

```
GET /api/v1/asbuilt/subcontractors
```

Returns all subcontractors. Use the `id` as `subcontractor_id` in the import payload.

**Response `200`:**

```json
[
  { "id": 1, "name": "XYZ Contractors" },
  { "id": 2, "name": "Meridian Infra"  },
  { "id": 3, "name": "ABC Subcon"      }
]
```

**forEach pattern:**

```js
const subRes  = await fetch(`${BASE_URL}/asbuilt/subcontractors`, { headers })
const subcons = await subRes.json()

subcons.forEach(sub => {
  displaySubcontractorOption({ id: sub.id, name: sub.name })
})
```

---

### 9. List Teams

```
GET /api/v1/asbuilt/teams?subcontractor_id={id}
```

Returns all teams. Filter by `subcontractor_id` to show only teams under the selected subcontractor. Use the `id` as `team_id` in the import payload.

**Query parameter:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `subcontractor_id` | integer | ❌ | Filter teams by subcontractor. Omit to return all teams. |

**Response `200`:**

```json
[
  { "id": 5,  "name": "Team Bravo", "subcontractor_id": 3 },
  { "id": 7,  "name": "Team Alpha", "subcontractor_id": 3 },
  { "id": 12, "name": "Team Delta", "subcontractor_id": 3 }
]
```

**Full subcontractor → team forEach pattern:**

```js
// Step 1 — Load all subcontractors
const subRes  = await fetch(`${BASE_URL}/asbuilt/subcontractors`, { headers })
const subcons = await subRes.json()

subcons.forEach(sub => {
  displaySubcontractorOption({ id: sub.id, name: sub.name })
})

// Step 2 — User picks a subcontractor → load its teams
const selectedSubcon = subcons[0]  // whichever the user selected

const teamRes = await fetch(
  `${BASE_URL}/asbuilt/teams?subcontractor_id=${selectedSubcon.id}`,
  { headers }
)
const teams = await teamRes.json()

teams.forEach(team => {
  displayTeamOption({ id: team.id, name: team.name })
})

// Step 3 — Include both IDs in the import payload
const selectedTeam = teams[0]  // whichever the user selected

const payload = {
  node_id:          targetNode.node_id,
  node_name:        targetNode.name,
  area_id:          selectedArea.id,
  subcontractor_id: selectedSubcon.id,
  team_id:          selectedTeam.id,
  poles: [...],
  spans: [...]
}
```

> Both `subcontractor_id` and `team_id` are optional — if skipped, the node saves without them and can be assigned later from the web dashboard.

---

### 6. Index-Based Import

```
POST /api/v1/asbuilt/import-by-sequence
Content-Type: application/json
```

Alternative to `/asbuilt/import` designed for DXF exports where poles have string identifiers like `"NPT-1"`, `"CV8-001"`. Every pole carries a required `pole_index` string. Spans reference poles by `from_pole_index` / `to_pole_index` — no `pole_code` ambiguity.

**Key difference from `/import`:**

| | `/import` | `/import-by-sequence` |
|---|---|---|
| `pole_index` type | integer (1, 2, 3) | string (`"NPT-1"`, `"CV8-001"`) |
| Span matching | `from_pole_index` integer + `from_pole_code` | `from_pole_index` string only |
| Use case | Standard pole count import | DXF export with named poles |

**Payload:**

```json
{
  "node_id":   "TY1401",
  "node_name": "MONTEVISTA SUBD.",
  "area_id":   1,
  "region":    "CALABARZON",
  "province":  "LAGUNA",
  "city":      "STA. ROSA",
  "poles": [
    { "pole_index": "NPT-1", "pole_code": "NPT", "lat": 14.539770, "lng": 121.109219 },
    { "pole_index": "NPT-2", "pole_code": "NPT", "lat": 14.540100, "lng": 121.109800 },
    { "pole_index": "CV8-001", "pole_code": "CV8", "lat": 14.540750, "lng": 121.110300 }
  ],
  "spans": [
    {
      "from_pole_index": "NPT-1", "to_pole_index": "NPT-2",
      "strand_length": 50.5, "number_of_runs": 1,
      "components": { "node": 2, "amplifier": 1, "extender": 0, "tsc": 1, "powersupply": 0, "ps_housing": 0 }
    },
    {
      "from_pole_index": "NPT-2", "to_pole_index": "CV8-001",
      "strand_length": 45.0, "number_of_runs": 2,
      "components": { "node": 1, "amplifier": 0, "extender": 1, "tsc": 0, "powersupply": 1, "ps_housing": 1 }
    }
  ]
}
```

**Pole fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `pole_index` | string | ✅ | Unique identifier for this pole — e.g. `"NPT-1"`, `"CV8-001"`. Saved to `skycable_poles.pole_index` |
| `pole_code` | string | ✅ | Original DXF pole label e.g. `"NPT"`. Stored UPPERCASE |
| `lat` / `latitude` | decimal | ✅ **Required** | GPS latitude of the pole. −90 to 90 |
| `lng` / `longitude` | decimal | ✅ **Required** | GPS longitude of the pole. −180 to 180 |
> Do NOT send `sequence` — it is reserved for the lineman mobile app teardown order.
> Do NOT send `barangay_name` on poles — it has been removed from the import contract.

**Span fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_pole_index` | string | ✅ | `pole_index` of the starting pole e.g. `"NPT-1"` |
| `to_pole_index` | string | ✅ | `pole_index` of the ending pole e.g. `"CV8-001"` |
| `strand_length` | decimal | ✅ **Required** | Length in meters. e.g. `50.5` |
| `number_of_runs` | integer | ✅ **Required** | Minimum 1. `expected_cable = strand_length × number_of_runs` |
| `components` | object | ❌ | Same structure as `/import` |

**Why string `pole_index` here:**

When a DXF has multiple poles with the same name (`NPT`, `PT`, `NT`), `pole_index` gives each a unique human-readable ID (`NPT-1`, `NPT-2`, `NPT-3`). Spans reference these exact IDs — `NPT-1` always connects to the correct physical pole regardless of name collisions.

**Response `201`:** Same shape as `/import` response.

---

---

### 7. Backfill Span Codes

```
POST /api/v1/asbuilt/backfill-span-codes
```

One-time utility endpoint. Scans all existing spans that have no `span_code` and generates one for each using the standard city-initials format. Run this once after deploying the span code feature to existing data.

**Response `200`:**

```json
{
  "message": "Backfilled 142 span codes.",
  "updated": 142
}
```

> Safe to call multiple times — only affects spans where `span_code IS NULL`. Already-coded spans are untouched.

```bash
curl -X POST "$BASE/asbuilt/backfill-span-codes" \
  -H "X-AsBuilt-Key: $KEY"
```

---

## Lineman-Collected Data Per Span (Mobile App Reference)

> **For future update** — the `GET /asbuilt/node/{nodeId}` verify endpoint should eventually return `collected` values alongside `expected` values per span, so the dashboard can show progress per span.

Every span in the mobile app (components.tsx) has two stages the lineman fills in:

### Stage 1 — Cable

| Field sent to backend | Source in mobile app | Description |
|---|---|---|
| `did_collect_all_cable` | `collectedAll` boolean | `1` = collected all, `0` = partial |
| `collected_cable` | `recoveredCable` input (meters) | Actual cable weight/length collected |
| `declared_runs` | `declared_runs` from AsBuilt | Number of runs as imported |
| `actual_runs` | `actualRuns` counter | Actual runs lineman counted on site |
| `expected_cable` | `expected_cable` from AsBuilt | `strand_length × number_of_runs` |
| `recovered_cable` | same as `collected_cable` | Filled only when `did_collect_all_cable = 0` |
| `unrecovered_cable` | `adjExpected − recoveredNum` | How much was not collected |
| `unrecovered_reason` | `cableReason` text input | Reason for short collection |

### Stage 2 — Components

These are the **6 collectable hardware items** per span. The lineman increments counters via `+` / `−` buttons (CounterCard):

| Mobile state | Field sent to backend | `expected_` from AsBuilt | Label shown to lineman |
|---|---|---|---|
| `collectedNode` | `nodes_collected` | `expected_node` | Node |
| `collectedAmp` | `amplifiers_collected` | `expected_amplifier` | Amplifier |
| `collectedExt` | `extenders_collected` | `expected_extender` | Extender |
| `collectedTsc` | `tsc_collected` | `expected_tsc` | TSC |
| `collectedPs` | `powersupply_collected` | `expected_powersupply` | Power Supply |
| `collectedPsh` | `ps_housing_collected` | `expected_powersupply_housing` | PS Housing |

> `did_collect_components` = `"1"` if any of the 6 counts are > 0, else `"0"`.

### Photos Per Span (7 required)

| Backend field | Label in app | Required |
|---|---|---|
| `from_before` | From Pole — Before | ✅ |
| `from_after` | From Pole — After | ✅ |
| `from_pole_tag` | From Pole — Pole Tag | ✅ |
| `to_before` | Destination Pole — Before | ✅ |
| `to_after` | Destination Pole — After | ✅ |
| `to_pole_tag` | Destination Pole — Pole Tag | ✅ |
| `bunching` | Bunching / Cable photo | ✅ |

### Future Update — Show Collected vs Expected Per Span

The verify endpoint currently only returns `expected_cable` and `expected` component counts (from AsBuilt import). When the teardown report is submitted, the backend has:

```
span.teardown_report.nodes_collected      vs  span.summary.expected_node
span.teardown_report.amplifiers_collected vs  span.summary.expected_amplifier
span.teardown_report.extenders_collected  vs  span.summary.expected_extender
span.teardown_report.tsc_collected        vs  span.summary.expected_tsc
span.teardown_report.powersupply_collected vs span.summary.expected_powersupply
span.teardown_report.ps_housing_collected  vs span.summary.expected_ps_housing
span.teardown_report.collected_cable      vs  span.summary.expected_cable
```

Proposed addition to `GET /asbuilt/node/{nodeId}` span response:

```json
{
  "span_id": 1,
  "from_pole_code": "PL-001",
  "to_pole_code": "PL-002",
  "strand_length": 50.5,
  "number_of_runs": 1,
  "expected_cable": 50.5,
  "status": "submitted",
  "components": {
    "node":        { "expected": 2, "collected": 2 },
    "amplifier":   { "expected": 1, "collected": 1 },
    "extender":    { "expected": 0, "collected": 0 },
    "tsc":         { "expected": 1, "collected": 1 },
    "powersupply": { "expected": 0, "collected": 0 },
    "ps_housing":  { "expected": 0, "collected": 0 }
  },
  "cable": {
    "expected": 50.5,
    "collected": 48.0,
    "did_collect_all": false,
    "unrecovered": 2.5,
    "reason": "Cut section left on pole"
  }
}
```

---

## Error Handling

| HTTP Status | Meaning |
|-------------|---------|
| `201` | Import completed (may include `errors[]` for skipped spans) |
| `422` | Validation failed — missing required fields or invalid values |
| `422` | **Duplicate node_id** — a node with this `node_id` already exists in the `area_id`. Change the `node_id` and retry. Message: `"Import failed. This node id (…) already exists on the backend. Please double check properly to avoid node id duplication."` |
| `404` | `area_id` not found |

Both `/asbuilt/import` and `/asbuilt/import-by-sequence` reject duplicate `node_id`s the same way.

When a span's `from_pole_index` or `to_pole_index` does not match any pole in the `poles` list, that span is **silently skipped** and recorded in `data.errors[]`. The rest of the import still succeeds with a `201`.

```json
{
  "message": "AsBuilt import completed.",
  "data": {
    "node": { ... },
    "poles_created": ["PL-001", "PL-002"],
    "spans_created": [],
    "total_poles": 2,
    "total_spans": 0,
    "errors": [
      "spans[0]: from_pole_index '99' not found in poles list"
    ]
  }
}
```

---

## cURL Examples

```bash
# Production domain
BASE="https://telcovantage.com/api/v1"
KEY="asbuilt-iq-secret-key-2026"

# Local Wi-Fi only (same network as server)
# BASE="http://192.168.1.17:8080/api/v1"

# 1 — List sites / areas
curl "$BASE/asbuilt/sites" \
  -H "X-AsBuilt-Key: $KEY"

# 2 — List nodes for NCR, area id = 1
curl "$BASE/asbuilt/sites/1/nodes" \
  -H "X-AsBuilt-Key: $KEY"

# 3 — Import via JSON body
curl -X POST "$BASE/asbuilt/import" \
  -H "X-AsBuilt-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "node_id":   "TY1401",
    "node_name": "MONTEVISTA SUBD.",
    "area_id":   1,
    "region":    "CALABARZON",
    "province":  "LAGUNA",
    "city":      "STA. ROSA",
    "poles": [
      {
        "pole_index":    1,
        "pole_code":     "PL-001",
        "latitude":      14.53977,
        "longitude":     121.10921
        },
      {
        "pole_index":    2,
        "pole_code":     "PL-002",
        "latitude":      14.54010,
        "longitude":     121.10980
        }
    ],
    "spans": [
      {
        "from_pole_code":  "PL-001",
        "to_pole_code":    "PL-002",
        "from_pole_index": 1,
        "to_pole_index":   2,
        "strand_length":   50.5,
        "number_of_runs":  1,
        "components": {
          "node": 2, "amplifier": 1, "extender": 0,
          "tsc": 1, "powersupply": 0, "ps_housing": 0
        }
      }
    ]
  }'

# 4 — Import via file upload
curl -X POST "$BASE/asbuilt/import" \
  -H "X-AsBuilt-Key: $KEY" \
  -F "file=@export.json"

# 5 — Verify node state (use integer id from import response)
curl "$BASE/asbuilt/node/10" \
  -H "X-AsBuilt-Key: $KEY"
```
