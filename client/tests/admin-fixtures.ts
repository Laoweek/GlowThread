// Builders for queue items in the hub's tests.
import type { Comment, QueueItem, QueueResponse } from '@/shared/types'

let nextId = 1000

export function comment(over: Partial<Comment> = {}): Comment {
  const id = over.id || nextId++
  return {
    id, seq: id % 100, pageId: 42, parentId: null, threadId: null, depth: 0, status: 'visible',
    author: { name: 'Alice', id: 3, anon: false, temp: false, hidden: false },
    created: '2026-10-08T00:00:00Z', edited: null, segments: [{ type: 'text', text: 'text ' + id }],
    likes: 0, pinned: false, replyTo: null,
    viewer: { own: false, liked: false, reported: false, canReply: false, canLike: false, canReport: false, canDelete: false },
    mod: { holdReasons: [], openReports: 0, reportReasons: [], statusBy: null, statusAt: null, pinnedAt: null, legacyId: null },
    ...over,
  }
}

const reporter = (name: string) => ({ name, id: 9, anon: false, temp: false, hidden: false })

/** A queue item; `reports` are reporter names (each one report with `reason`). */
export function item(over: Partial<Comment> = {}, reports: string[] = [], reason: 'spam' | 'abuse' | 'other' = 'spam'): QueueItem {
  const c = comment(over)
  if (reports.length && c.mod) {
    c.mod.openReports = reports.length
    c.mod.reportReasons = [{ reason, count: reports.length }]
  }
  return {
    comment: c,
    page: { id: c.pageId, title: 'Sourdough starter', exists: true },
    context: null,
    reports: reports.map((name, i) => ({ id: c.id * 10 + i, reason, note: '', created: null, reporter: reporter(name) })),
  }
}

export function page(items: QueueItem[], next: string | null = null, counts = { reported: 5, held: 3, deleted: 2 }, tab: QueueResponse['tab'] = 'reported'): QueueResponse {
  return { tab, items, next, counts }
}

/** The answer of glowthreadmoderate for these IDs. */
export function moderated(ids: number[], status: string, overrides: Record<number, { result: string, code?: string, status?: string | null }> = {}) {
  return {
    result: 'success',
    do: 'x',
    results: ids.map((id) => ({ id, result: 'success', status, code: null, ...(overrides[id] || {}) })),
  }
}
