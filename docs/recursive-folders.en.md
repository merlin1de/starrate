# Recursive folder view

> Deutsche Version: [recursive-folders.de.md](recursive-folders.de.md)

By default, StarRate shows only images directly inside the current folder. The recursive view additionally pulls in every image from subfolders and presents them as a single flat stream.

## Enabling

In your personal settings under **Personal → StarRate → Recursive view**:

1. Tick **"Enable recursive view"** — unlocks the feature for your account.
2. Only after this do the additional options and the toolbar toggle in the gallery appear.

While the master switch is off, StarRate behaves exactly as before (current folder only).

## Configuring

Three places where recursive/depth can be set — evaluated top-down:

| Source | Effect |
|---|---|
| **URL query** (`?recursive=1&depth=2`) | Highest priority, overrides everything. Handy for sharing a specific view. |
| **Per folder** (gallery toolbar) | Remembered — the next time you open the same folder, your last choice is restored. |
| **Default** (settings) | Applies when neither URL nor per-folder memory has anything to say. |

In settings, two defaults can be set:

- **Recursive by default** — new folders open recursively right away. Turn off if you mostly work folder-by-folder.
- **Group depth** — sort modifier inside the recursive view (see below).

## Group depth — what does it do?

Depth is a **sort modifier**, not a layout feature. There are no visible group headers, no separators — only the order changes, so that images sharing a path prefix end up next to each other.

Example folder tree:

```
/Wedding
├── Preparation/
│   ├── IMG_001.jpg
│   └── IMG_002.jpg
├── Ceremony/
│   └── IMG_003.jpg
└── Reception/
    ├── IMG_004.jpg
    └── Dance/
        └── IMG_005.jpg
```

You open `/Wedding` recursively. Secondary sort is whatever you have configured in NC settings (e.g. file name).

| Depth | Order | Effect |
|---|---|---|
| **0 (Flat)** | 001, 002, 003, 004, 005 | Pure user sort, path ignored. |
| **1** | 001, 002 \| 003 \| 004, 005 | Sorted primarily by 1st subfolder: everything from `Preparation`, then `Ceremony`, then `Reception/*`. |
| **2** | 001, 002 \| 003 \| 004 \| 005 | Sorted by 2 path segments: `Reception/Dance` is now its own block, separate from `Reception`. |
| **3–4** | 001, 002 \| 003 \| 004 \| 005 | Same as depth 2, since the tree doesn't go any deeper. |

Good to know:

- Filters (stars, colors, pick) apply **before** the depth sort — you'll see only matching images, still grouped by prefix.
- Depth = 0 is usually the right pick for chronological sorting ("everything from this weekend, no matter which folder").
- Depth ≥ 1 helps when you want to keep folder context as you scroll (Preparation before Ceremony before Reception).

## Folder cache

StarRate remembers per folder, in your browser's `localStorage`, whether you opened it recursively and at which depth.

- Storage key: `starrate_folder_recursive_v1`
- Up to **50 folders** — beyond that, LRU eviction (most-recently-seen folder wins).
- Browser-local, **not** synced with your NC account. On a different device you start over with the defaults.
- Clear browser data → cache gone → defaults apply again.

Practical effect: flip recursive on once for a wedding folder, and it stays that way next time. Meanwhile a folder like `Inbox` can stay non-recursive if you configured it that way.

## Guest share

When creating or editing a guest link, the recursive view can be **wired into the share itself** — independently of what you have configured locally.

In the share dialog (only visible if recursive view is enabled in your settings):

- **"Recursive"** — the guest sees all images below the shared folder.
- **"Group depth"** — sort modifier for the guest (same semantics as above).

Use cases:

- The wedding couple gets a link to `/Wedding` recursive with depth 1 → sees every image, grouped by Preparation/Ceremony/Reception.
- A model gets a link to `/Shoot/2026-04-28` non-recursive → sees only that day's set, no neighbouring sessions.
- An existing share can be flipped from "flat" to "recursive with depth 2" later, without the link changing for the guest.

The guest themselves has **no** toggle — what you pick in the share dialog is what they see.
