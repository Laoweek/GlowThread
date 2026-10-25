import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/api'
import { describeError, errorMessage } from '@/lib/errors'

describe('errorMessage', () => {
  it('names the problem and the recovery for each backend code', () => {
    expect(errorMessage(new ApiError('commentslocked'))).toBe('Comments on this page are locked. You cannot post or reply.')
    expect(errorMessage(new ApiError('badtoken'))).toContain('Reload the page')
    expect(errorMessage(new ApiError('network'))).toContain('Check your connection')
    expect(errorMessage(new ApiError('comment-not-found'))).toContain('was removed')
  })
  it('passes limits as parameters', () => {
    expect(errorMessage(new ApiError('toolong', '', { max: 1000 }))).toBe('A comment can be at most 1000 characters long. Shorten it and send again.')
    expect(errorMessage(new ApiError('toomanymentions', '', { max: 5 }))).toContain('5 people')
    expect(errorMessage(new ApiError('toomanyheld', '', { max: 3 }))).toBe('You have 3 comments waiting for review. Please wait until a moderator has reviewed them before posting more.')
  })
  it('shows the wait and the block reason when the server gives them', () => {
    expect(errorMessage(new ApiError('ratelimited', '', { retryAfter: 41.2 }))).toBe('You are doing that too often. Try again in 42 seconds.')
    expect(errorMessage(new ApiError('ratelimited'))).toBe('You are doing that too often. Wait a moment and try again.')
    expect(errorMessage(new ApiError('blocked', '', { blockinfo: { blockreason: 'Flooding' } }))).toContain('Reason: Flooding')
  })
  it('says when an account the new-account gate holds can like', () => {
    expect(errorMessage(new ApiError('newaccount'))).toBe('You can like comments once your account is a little older or one of your comments is approved.')
  })
  it('asks logged-out readers to log in instead of naming a right', () => {
    expect(errorMessage(new ApiError('permissiondenied'), { anon: true })).toBe('Please log in first.')
    expect(errorMessage(new ApiError('permissiondenied'))).toBe('You do not have permission to do that.')
  })
  it('falls back for unknown codes and non-API errors', () => {
    expect(describeError(new ApiError('somethingnew')).key).toBe('error-unknown')
    expect(describeError(new Error('boom')).key).toBe('error-unknown')
  })
})
