// Shapes of the GlowThread REST and Action API (docs/api.md). Shared by the comment
// section and the moderation app.
import type { Segment } from '@/lib/segments'

export type Sort = 'hot' | 'new'
export type PageState = 'open' | 'locked' | 'disabled'
export type CommentStatus = 'visible' | 'held' | 'deleted' | 'erased'
export type HoldReason = 'filter' | 'filter-error' | 'spamblacklist' | 'newaccount' | 'reports' | 'parentheld'
export type ReportReason = 'spam' | 'abuse' | 'offtopic' | 'spoiler' | 'misinfo' | 'other' | 'legacy'

export interface Author {
  name: string | null
  id: number
  anon: boolean
  temp: boolean
  hidden: boolean
  /** Moderators only: registration time (null for IPs and very old accounts). */
  registration?: string | null
  /** Moderators only: a current block of the account (or exact IP). */
  blocked?: 'sitewide' | 'partial' | null
}

export interface CommentViewer {
  own: boolean
  liked: boolean
  reported: boolean
  canReply: boolean
  canLike: boolean
  canReport: boolean
  canDelete: boolean
}

export interface ReplyTo {
  id: number
  seq: number | null
  visible: boolean
  author: Author | null
}

export interface ModInfo {
  holdReasons: HoldReason[]
  openReports: number
  reportReasons: Array<{ reason: ReportReason, count: number }>
  statusBy: Author | null
  statusAt: string | null
  pinnedAt: string | null
  legacyId: string | null
  /** Word-filter rules that held the comment, as the filter page was then (may be empty). */
  holdMatches?: Array<{ line: number, rule: string, type: string }>
  /** For a deleted comment: the status before the deletion, which restore goes back to (null when unknown). */
  deletedFrom?: 'visible' | 'held' | null
}

export interface Comment {
  id: number
  seq: number
  pageId: number
  parentId: number | null
  threadId: number | null
  depth: number
  status: CommentStatus
  author: Author
  created: string
  edited: string | null
  segments: Segment[]
  likes: number
  pinned: boolean
  replyTo: ReplyTo | null
  viewer: CommentViewer
  replyCount?: number
  totalReplies?: number
  replies?: Comment[]
  mod?: ModInfo
}

export interface PageInfo {
  id: number
  title: string
  state: PageState
}

// 'ratelimited' is not sent today: the composer stays and says to wait.
export type PostBlockedReason = 'login' | 'rights' | 'blocked' | 'locked' | 'disabled' | 'ineligible' | 'ratelimited' | 'readonly' | null

export interface ListViewer {
  loggedIn: boolean
  canPost: boolean
  postBlockedReason: PostBlockedReason
  canReact: boolean
  /** Why canReact is false when it is only for now: 'newaccount' (the new-account gate). Absent from older servers. */
  reactBlockedReason?: 'newaccount' | null
  canReport: boolean
  canModerate: boolean
  canErase: boolean
  canConfigure: boolean
}

export interface ListConfig {
  maxLength: number
  maxMentions: number
  reportReasons: ReportReason[]
  pageSize: number
  replyPreview: number
}

export interface ListResponse {
  page: PageInfo
  sort: Sort
  viewer: ListViewer
  config: ListConfig
  count: number
  pinned: Comment[]
  popular: Comment[]
  comments: Comment[]
  next: string | null
}

export interface ThreadResponse {
  page: PageInfo
  focus: number
  thread: Comment
  truncated: boolean
}

/** GET /comment/{id}/replies: the next replies under one comment, oldest first. */
export interface RepliesResponse {
  parentId: number
  replies: Comment[]
  next: string | null
}

export interface QueueItem {
  comment: Comment
  page: { id: number, title: string, exists: boolean }
  context: Comment | null
  reports: Array<{ id: number, reason: ReportReason, note: string, created: string | null, reporter: Author }>
}

export interface QueueResponse {
  tab: 'reported' | 'held' | 'deleted' | 'all'
  /** The order the server used. */
  sort?: 'reports' | 'new' | 'old'
  items: QueueItem[]
  next: string | null
  counts: { reported: number, held: number, deleted: number }
}

export interface PageHistoryResponse {
  page: {
    id: number
    title: string | null
    exists: boolean
    state: PageState
    floors: number
    stateChangedBy?: Author | null
    stateChangedAt?: string | null
  }
  counts: { visible: number, held: number, deleted: number, erased: number, reported: number }
  moderation?: LogEntry[]
}

/**
 * A comment log entry (Special:Log/glowthread) in the moderation records. Hidden fields
 * (revision deletion) are null.
 */
export interface LogEntry {
  id: number
  action: string
  timestamp: string
  performer: string | null
  commentId: number | null
  seq: number | null
  reason: string | null
}

export interface UserBlock {
  sitewide: boolean
  /** 'infinity' or an ISO time. */
  expiry: string
  reason: string
  by: string | null
}

/** GET /moderation/user/{name}. */
export interface UserHistoryResponse {
  user: {
    name: string
    id: number
    actorId: number | null
    registered: boolean
    registration: string | null
    editCount: number | null
    groups: string[]
    blocks: UserBlock[]
  }
  /** `erased` is null for moderators without glowthread-erase. */
  counts: { visible: number, held: number, deleted: number, erased: number | null }
  reports: { filed: number, open: number, upheld: number, dismissed: number }
  comments: QueueItem[]
  next: string | null
  moderation: LogEntry[]
}

export interface SettingInfo {
  value: number | boolean | string[]
  default: number | boolean | string[]
  editable: boolean
  overridden: boolean
  type: 'int' | 'bool' | 'list'
  min?: number
  max?: number
  /** The lowest value that turns the setting on: `min` turns it off, and values in between are refused (autoHoldReporters). */
  minOn?: number
  options?: string[]
  minItems?: number
  changedAt?: string
  changedByActor?: number
}

export interface InvalidRule {
  line: number
  rule: string
  error: string
}

/** One $wgRateLimits key as the settings route may report it (docs/api.md). */
export interface RateLimitInfo {
  key: string
  limits: Record<string, [number, number]>
  /** False when noratelimit does not exempt sysops (erase, configure). */
  canBypass?: boolean
}

/** GET /settings. */
export interface SettingsResponse {
  settings: Record<string, SettingInfo>
  canConfigure: boolean
  filter: { page: string, rules: number, invalid: InvalidRule[] }
  rateLimits?: RateLimitInfo[]
}

export interface SettingsResult {
  result: string
  /** The comments/settings log entry. */
  logId?: number | null
  changed: Array<{ name: string, old: unknown, new: unknown }>
  settings: Record<string, SettingInfo>
}

export interface FilterTestResult {
  held: boolean
  matches: Array<{ line: number, rule: string, type: string }>
  errors: Array<{ line: number, rule: string, error?: string }>
  invalid: InvalidRule[]
  rules: number
}

export interface PostResult {
  result: string
  id: number
  seq: number
  status: CommentStatus
  /** Moderators get every reason. Others may get only those about their account, or none: the app does not show them. */
  holdReasons?: HoldReason[]
  comment: Comment
}

export interface ReactResult { result: string, id: number, liked: boolean, likes: number, changed: boolean }
export interface ReportResult { result: string, id: number, already: boolean, held: boolean }
export interface DeleteResult { result: string, id: number, status: CommentStatus }
export interface ModerateResult {
  result: 'success' | 'partial' | 'failure'
  do: string
  // logId and old (the status before), for exact undo (docs/api.md). Absent from servers that
  // do not send them.
  results: Array<{ id: number, result: 'success' | 'nochange' | 'error', status: CommentStatus | null, code: string | null, logId?: number | null, old?: CommentStatus | null }>
}
export interface PageStateResult { result: string, pageid: number, state: PageState, old: PageState, changed: boolean }

/** mw.config wgGlowThreadAdmin, on Special:GlowThread. */
export interface AdminConfig {
  subPage: string
  restBase: string
  canErase: boolean
  canConfigure: boolean
}

/** mw.config wgGlowThread, added to eligible article views. */
export interface PageConfig {
  pageId: number
  enabled: boolean
  state: PageState
  defaultSort: Sort
  listUrls: { hot: string, new: string }
  restBase: string
  features: { legacyCompat: boolean }
}
