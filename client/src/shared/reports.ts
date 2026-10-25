// Report reasons of a comment grouped with their reporters, for the hub's queue items and the
// comment section's moderation notes.
import type { QueueItem } from './types'

export interface ReportGroup { reason: string, count: number, reporters: string[], notes: string[] }

/** Report reasons of an item, grouped with counts and reporters, most frequent first. */
export function groupReports(item: Pick<QueueItem, 'reports' | 'comment'>): ReportGroup[] {
  const groups: Record<string, ReportGroup> = {}
  const order: string[] = []
  for (const r of item.reports) {
    let g = groups[r.reason]
    if (!g) {
      g = groups[r.reason] = { reason: r.reason, count: 0, reporters: [], notes: [] }
      order.push(r.reason)
    }
    g.count++
    const name = r.reporter && !r.reporter.hidden ? r.reporter.name : null
    if (name && g.reporters.indexOf(name) < 0) g.reporters.push(name)
    if (r.note) g.notes.push(r.note)
  }
  // Reports beyond the 10 the API lists are counted by reason in comment.mod.
  const mod = item.comment.mod
  if (mod) {
    for (const rr of mod.reportReasons) {
      const g = groups[rr.reason]
      if (g && rr.count > g.count) g.count = rr.count
      else if (!g) {
        groups[rr.reason] = { reason: rr.reason, count: rr.count, reporters: [], notes: [] }
        order.push(rr.reason)
      }
    }
  }
  return order.map((k) => groups[k]).sort((a, b) => b.count - a.count)
}
