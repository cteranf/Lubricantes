import test from 'node:test'
import assert from 'node:assert/strict'
import { createRefundManager, refundParams, refundLabel, refundMoney, safeRefundError } from '../../resources/js/treasury/refundManager.mjs'

const row = (id = 1, flags = {}) => ({ order_id: id, order_number: `#${id}`, amount: '50.00', currency: 'PEN', can_mark_refund_pending: true, can_complete_refund: false, requires_manual_review: false, refund: null, ...flags })
const list = (rows = [row()], page = 1, last = 1) => ({ data: { data: rows, meta: { total: rows.length, current_page: page, last_page: last, per_page: 20 } } })
const detail = value => ({ data: { data: value } })
const failure = status => ({ response: { status, data: { message: 'synthetic-untrusted-message', errors: { reason: ['synthetic-untrusted-message'], external_reference: ['synthetic-untrusted-message'] } } } })
const deferred = () => { let resolve; let reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const gets = []; const posts = []
  const api = {
    get: async (url, config) => { gets.push({ url, config }); return url === '/treasury/refund-worklist' ? list() : detail(row()) },
    post: async (url, payload) => { posts.push({ url, payload }) },
  }
  const manager = createRefundManager(api)
  return { api, manager, s: manager.state, gets, posts }
}
async function ready(mode = 'pending') {
  const h = harness()
  if (mode === 'complete') h.api.get = async url => url === '/treasury/refund-worklist' ? list() : detail(row(1, { can_mark_refund_pending: false, can_complete_refund: true, refund: { id: 4, status: 'pending' } }))
  await h.manager.openDetail(row())
  h.manager.openAction(mode)
  h.s.reason = 'synthetic reason'
  h.s.external_reference = 'synthetic reference'
  h.s.confirmed = true
  return h
}

test('exact read endpoints and scalar unique bounded filters', async () => {
  const h = harness()
  h.s.filters = { ...h.s.filters, order: '#1', payment_method: 'card', refund_status: 'pending', date_from: '2026-01-01', date_to: '', per_page: 99, unknown: 'ignored' }
  await h.manager.apply(); await h.manager.openDetail(row(7))
  const params = h.gets[0].config.params
  assert.ok(h.gets[0].url === '/treasury/refund-worklist' && h.gets[1].url === '/treasury/orders/7/refund-state')
  assert.ok(params.per_page === 20 && params.page === 1 && !('date_to' in params) && !('unknown' in params))
  assert.ok(Object.values(params).every(value => !Array.isArray(value)))
  assert.ok(Object.keys(params).length === new Set(Object.keys(params)).size)
})
test('apply and clear reset page; pagination and total come from server', async () => {
  const h = harness(); h.s.meta = { current_page: 4 }
  h.api.get = async (url, config) => { h.gets.push({ url, config }); return list([], config.params.page, 4) }
  await h.manager.apply(); await h.manager.load(3); await h.manager.clear()
  assert.ok(h.gets.map(g => g.config.params.page).join(',') === '1,3,1')
  assert.ok(h.s.meta.total === 0 && h.s.rows.length === 0)
  assert.ok(!('order' in refundParams({ order: ['1'] })))
})
test('only strict backend booleans allow actions, irrespective of payment projections', async () => {
  const h = harness(); await h.manager.openDetail(row())
  h.s.detail = row(1, { can_mark_refund_pending: false, can_complete_refund: false, order_payment_status: 'approved' })
  assert.ok(!h.manager.can('pending') && !h.manager.can('complete'))
  h.s.detail.can_mark_refund_pending = 'true'; assert.ok(!h.manager.can('pending'))
  h.s.detail.can_mark_refund_pending = true; assert.ok(h.manager.can('pending'))
  h.s.detail.requires_manual_review = true
  assert.ok(!h.manager.can('pending') && !h.manager.can('complete'))
  assert.ok(refundLabel(h.s.detail) === 'Requiere revisión manual')
})
for (const mode of ['pending', 'complete']) {
  test(`${mode}: exact payload, explicit confirmation and double submission blocked`, async () => {
    const h = await ready(mode); const post = deferred(); h.api.post = (url, payload) => { h.posts.push({ url, payload }); return post.promise }
    h.s.confirmed = false; assert.ok(await h.manager.submit() === false && h.posts.length === 0)
    h.s.confirmed = true
    const command = h.manager.submit(); await h.manager.submit(); h.manager.openAction(mode === 'pending' ? 'complete' : 'pending')
    assert.ok(h.posts.length === 1 && h.s.commandLoading)
    const expected = mode === 'pending' ? { reason: 'synthetic reason' } : { external_reference: 'synthetic reference' }
    assert.ok(JSON.stringify(h.posts[0].payload) === JSON.stringify(expected), 'Only the contractual field may be sent.')
    assert.ok(h.posts[0].url === (mode === 'pending' ? '/treasury/orders/1/refunds' : '/treasury/payment-refunds/4/complete'))
    // Both reads are authoritative; command responses are never patched into local financial state.
    h.api.get = async url => url === '/treasury/refund-worklist' ? list() : detail(row(1, { can_mark_refund_pending: false, can_complete_refund: false }))
    post.resolve(); assert.ok(await command)
    assert.ok(h.s.reason === '' && h.s.external_reference === '' && h.s.actionMode === '')
    assert.ok(!h.manager.can('pending') && !h.manager.can('complete'))
  })
  test(`${mode}: 422 preserves raw text and never renders backend field messages`, async () => {
    const h = await ready(mode); h.s.reason = '  synthetic reason  '; h.s.external_reference = '  synthetic reference  '
    h.api.post = async () => { throw failure(422) }
    assert.ok(await h.manager.submit() === false)
    assert.ok(h.s.reason === '  synthetic reason  ' && h.s.external_reference === '  synthetic reference  ')
    assert.ok(!h.s.commandError.includes('synthetic-untrusted') && !h.s.fieldError.includes('synthetic-untrusted'))
    assert.ok(h.s.actionMode === mode && !h.s.blocked)
  })
}
test('409 never retries; only a new successful detail GET unlocks', async () => {
  const h = await ready(); let count = 0
  h.api.post = async () => { count++; throw failure(409) }
  await h.manager.submit(); await h.manager.submit()
  assert.ok(count === 1 && !h.manager.can('pending') && h.s.reason !== '')
  h.api.get = async () => { throw failure(500) }; await h.manager.refreshDetail()
  assert.ok(!h.manager.can('pending'))
  h.api.get = async () => detail(row()); await h.manager.refreshDetail()
  assert.ok(h.manager.can('pending')); await h.manager.submit(); assert.ok(count === 2)
})
for (const status of [401, 403, 404, 429, 500, undefined]) {
  test(`safe error ${status ?? 'network'} has no automatic retry or arbitrary body rendering`, async () => {
    const h = await ready(); let count = 0
    h.api.post = async () => { count++; throw status ? failure(status) : new Error('synthetic-untrusted-message') }
    await h.manager.submit(); await h.manager.submit()
    assert.ok(count === 1 && !h.s.commandError.includes('synthetic-untrusted'))
    if (status === 404) assert.ok(h.s.detail === null && h.s.detailStatus === 'missing')
    assert.ok(typeof safeRefundError(failure(status)) === 'string')
  })
}
test('POST success survives failed readback, never resends POST and allows manual update', async () => {
  const h = await ready(); h.api.get = async () => { throw failure(500) }
  assert.ok(await h.manager.submit())
  assert.ok(h.posts.length === 1 && h.s.notice !== '' && h.s.refreshWarning !== '' && h.s.commandError === '')
  h.api.get = async url => url === '/treasury/refund-worklist' ? list([]) : detail(row())
  assert.ok(await h.manager.update()); assert.ok(h.posts.length === 1 && h.s.refreshWarning === '')
})
test('success refreshes detail and current page, with server-directed page correction', async () => {
  const h = await ready(); h.s.meta = { current_page: 3 }; h.gets.length = 0
  h.api.get = async (url, config) => {
    h.gets.push({ url, config })
    return url === '/treasury/refund-worklist' ? list([], config.params.page, 2) : detail(row())
  }
  await h.manager.submit()
  assert.ok(h.gets.map(g => g.url).join('|') === '/treasury/orders/1/refund-state|/treasury/refund-worklist|/treasury/refund-worklist')
  assert.ok(h.gets[1].config.params.page === 3 && h.gets[2].config.params.page === 2)
})
test('a failed list refresh retains the requested page for the next manual update', async () => {
  const h = harness(); const pages = []
  h.api.get = async (url, config) => { pages.push(config.params.page); throw failure(500) }
  await h.manager.load(3); await h.manager.load()
  assert.ok(pages.join(',') === '3,3' && h.s.meta.total == null)
})
test('obsolete worklist responses cannot override new filters; identical in-flight read deduplicated', async () => {
  const h = harness(); const old = deferred(); const current = deferred(); let count = 0
  h.api.get = () => (++count === 1 ? old.promise : current.promise)
  const first = h.manager.load(); const same = h.manager.load(); assert.ok(first === same && count === 1)
  h.s.filters.payment_method = 'card'; const second = h.manager.apply()
  current.resolve(list([row(2)])); await second
  old.resolve(list([row(1)])); await first
  assert.ok(h.s.rows[0].order_id === 2 && !h.s.listLoading)
})
test('late detail cannot replace another order, including late failures', async () => {
  const h = harness(); const old = deferred(); let count = 0
  h.api.get = () => ++count === 1 ? old.promise : Promise.resolve(detail(row(2)))
  const first = h.manager.openDetail(row(1)); await h.manager.openDetail(row(2))
  old.reject(failure(404)); await first
  assert.ok(h.s.selectedId === 2 && h.s.detail.order_id === 2 && h.s.detailStatus === 'loaded')
})
test('double selection of the same order shares the active detail request', async () => {
  const h = harness(); const pending = deferred(); let calls = 0
  h.api.get = () => { calls++; return pending.promise }
  const first = h.manager.openDetail(row()); const second = h.manager.openDetail(row())
  assert.ok(calls === 1)
  pending.resolve(detail(row())); await Promise.all([first, second])
  assert.ok(h.s.detailStatus === 'loaded')
})
test('GET in flight before POST cannot restore an obsolete action', async () => {
  const h = await ready(); const old = deferred(); const command = deferred()
  h.api.get = () => old.promise; const pendingList = h.manager.load()
  h.api.post = () => command.promise; const post = h.manager.submit()
  old.resolve(list([row(99)])); await pendingList
  assert.ok(h.s.rows.length === 0)
  h.api.get = async url => url === '/treasury/refund-worklist' ? list([]) : detail(row(1, { can_mark_refund_pending: false }))
  command.resolve(); await post; assert.ok(!h.manager.can('pending'))
})
test('unmount invalidates all late reads', async () => {
  const h = harness(); const pending = deferred(); h.api.get = () => pending.promise
  const request = h.manager.openDetail(row()); h.manager.dispose(); pending.resolve(detail(row()))
  await request; assert.ok(h.s.detail === null && !h.manager.can('pending'))
})
test('detail states distinguish missing, conflict and unexpected failure', async () => {
  const h = harness()
  for (const [code, state] of [[404, 'missing'], [409, 'conflict'], [500, 'error']]) {
    h.api.get = async () => { throw failure(code) }
    await h.manager.openDetail(row())
    assert.ok(h.s.detailStatus === state && h.s.detail === null)
  }
})
test('decimal money retains precision and labels never grant eligibility', () => {
  assert.ok(refundMoney('9999999999999999.99', 'PEN') === 'PEN 9,999,999,999,999,999.99')
  assert.ok(refundMoney(null, 'PEN') === '—')
  assert.ok(refundLabel(row(1, { can_mark_refund_pending: false, refund: { status: 'completed' } })) === 'Devolución completada')
})

const actionRow = (mode, id, refundId = id + 10) => row(id, {
  can_mark_refund_pending: mode === 'pending', can_complete_refund: mode === 'complete',
  refund: mode === 'complete' ? { id: refundId, status: 'pending' } : null,
})
for (const mode of ['pending', 'complete']) {
  test(`draft context: ${mode} cancellation and order switch cannot reuse text`, async () => {
    const h = await ready(mode)
    h.manager.closeAction()
    h.api.get = async () => detail(actionRow(mode, 2))
    await h.manager.openDetail(row(2)); h.manager.openAction(mode)
    assert.ok(h.s.reason === '' && h.s.external_reference === '', 'Another order must never receive the previous draft.')
    h.s.confirmed = true
    assert.ok(await h.manager.submit() === false && h.posts.length === 0, 'Empty new-context drafts must never submit.')
  })
  test(`draft context: ${mode} 422 retains only the exact current context`, async () => {
    const h = await ready(mode)
    const field = mode === 'pending' ? 'reason' : 'external_reference'
    const sentinel = h.s[field]
    h.api.post = async () => { throw failure(422) }
    await h.manager.submit()
    assert.ok(h.s[field] === sentinel, 'Validation must retain the same-context draft.')
    h.manager.closeAction()
    h.api.get = async () => detail(actionRow(mode, 2))
    await h.manager.openDetail(row(2)); h.manager.openAction(mode)
    assert.ok(h.s.reason === '' && h.s.external_reference === '', 'Switching after validation must discard the old-context draft.')
  })
  test(`draft context: ${mode} late order response cannot restore old text`, async () => {
    const h = await ready(mode); h.manager.closeAction()
    const old = deferred()
    h.api.get = url => url.includes('/1/') ? old.promise : Promise.resolve(detail(actionRow(mode, 2)))
    const readingA = h.manager.openDetail(row(1))
    await h.manager.openDetail(row(2)); h.manager.openAction(mode)
    old.resolve(detail(actionRow(mode, 1))); await readingA
    assert.ok(h.s.selectedId === 2 && h.s.reason === '' && h.s.external_reference === '', 'Late reads cannot restore discarded drafts.')
  })
  test(`draft context: ${mode} submit verifies identity beyond the visible inputs`, async () => {
    const h = await ready(mode)
    h.s.selectedId = 2
    h.s.detail = actionRow(mode, 2)
    assert.ok(await h.manager.submit() === false && h.posts.length === 0, 'A mismatched draft identity must never reach Axios.')
    assert.ok(h.s.actionMode === '' && h.s.blocked && h.s.reason === '' && h.s.external_reference === '')
  })
}
test('draft context: a replacement refund invalidates the same-order reference', async () => {
  const h = await ready('complete')
  h.api.get = async () => detail(actionRow('complete', 1, 99))
  await h.manager.refreshDetail()
  assert.ok(h.s.actionMode === '' && h.s.external_reference === '', 'A different refund cannot inherit an earlier reference.')
  h.manager.openAction('complete'); h.s.confirmed = true
  assert.ok(await h.manager.submit() === false && h.posts.length === 0)
})
test('draft context: actions never share text and unmount clears both fields', async () => {
  const h = await ready()
  h.manager.closeAction()
  h.api.get = async () => detail(actionRow('complete', 1))
  await h.manager.refreshDetail(); h.manager.openAction('complete')
  assert.ok(h.s.reason === '' && h.s.external_reference === '', 'A1 text must not appear in A2.')
  h.s.external_reference = 'synthetic context reference'
  h.manager.closeAction()
  h.api.get = async () => detail(actionRow('pending', 1))
  await h.manager.refreshDetail(); h.manager.openAction('pending')
  assert.ok(h.s.reason === '' && h.s.external_reference === '', 'A2 text must not appear in A1.')
  h.s.reason = 'synthetic context reason'; h.s.external_reference = 'synthetic context reference'
  h.manager.dispose()
  assert.ok(h.s.reason === '' && h.s.external_reference === '', 'Unmount must clear both private fields.')
})

for (const pendingFamily of ['list', 'detail']) {
  test(`warning generation: post refresh for A cannot warn after B loads (${pendingFamily})`, async () => {
    const h = await ready(); const old = deferred()
    h.api.get = url => {
      if (pendingFamily === 'list' && url === '/treasury/refund-worklist') return old.promise
      if (pendingFamily === 'detail' && url.includes('/1/')) return old.promise
      return Promise.resolve(url === '/treasury/refund-worklist' ? list() : detail(actionRow('pending', url.includes('/2/') ? 2 : 1)))
    }
    const command = h.manager.submit()
    // Acknowledge the POST; its readback is deliberately still pending.
    await Promise.resolve(); await Promise.resolve()
    assert.ok(h.s.notice !== '' && h.s.readbackPending)
    await h.manager.openDetail(row(2))
    const before = JSON.stringify({ selected: h.s.selectedId, detail: h.s.detail, notice: h.s.notice, error: h.s.detailError, warning: h.s.refreshWarning, can: h.manager.can('pending') })
    old.reject(failure(500)); assert.ok(await command)
    const after = JSON.stringify({ selected: h.s.selectedId, detail: h.s.detail, notice: h.s.notice, error: h.s.detailError, warning: h.s.refreshWarning, can: h.manager.can('pending') })
    assert.ok(before === after && h.s.refreshWarning === '' && h.posts.length === 1, 'Old post readbacks must not affect B or resend the command.')
  })
}
test('warning generation: current partial failure warns and full success clears its warning', async () => {
  const h = harness(); await h.manager.openDetail(row())
  h.api.get = async url => { if (url === '/treasury/refund-worklist') throw failure(500); return detail(row()) }
  assert.ok(await h.manager.update() === false && h.s.refreshWarning !== '')
  h.api.get = async url => url === '/treasury/refund-worklist' ? list() : detail(row())
  assert.ok(await h.manager.update() === true && h.s.refreshWarning === '' && h.posts.length === 0)
})
for (const outcome of ['success', 'failure']) {
  test(`warning generation: old ${outcome} cannot replace a newer warning`, async () => {
    const h = await ready(); const oldList = deferred()
    h.api.get = url => url === '/treasury/refund-worklist' ? oldList.promise : Promise.resolve(detail(row()))
    const first = h.manager.update()
    await Promise.resolve(); await Promise.resolve()
    h.api.get = async url => { if (url === '/treasury/refund-worklist') throw failure(500); return detail(row()) }
    await h.manager.submit()
    const warning = h.s.refreshWarning
    assert.ok(warning.startsWith('La operación fue exitosa.') && h.posts.length === 1)
    if (outcome === 'success') oldList.resolve(list()); else oldList.reject(failure(500))
    await first
    assert.ok(h.s.refreshWarning === warning, 'Older generations must neither clear nor replace a newer warning.')
  })
}
test('warning generation: filter changes invalidate a cycle without changing selected order', async () => {
  const h = harness(); await h.manager.openDetail(row())
  const oldList = deferred()
  h.api.get = url => url === '/treasury/refund-worklist' ? oldList.promise : Promise.resolve(detail(row()))
  const first = h.manager.update()
  h.s.filters.payment_method = 'card'
  h.api.get = async () => list([row(2)])
  await h.manager.apply()
  oldList.reject(failure(500)); await first
  assert.ok(h.s.selectedId === 1 && h.s.rows[0].order_id === 2 && h.s.refreshWarning === '', 'Invalidated filter generations must not publish warnings.')
})
test('warning generation: aborted read does not generate a refresh warning', async () => {
  const h = harness()
  h.api.get = async () => { throw { code: 'ERR_CANCELED' } }
  await h.manager.update()
  assert.ok(h.s.refreshWarning === '' && h.s.listError === '')
})
test('warning generation: unmount prevents pending cycles from changing messages', async () => {
  const h = harness(); const pending = deferred(); h.api.get = () => pending.promise
  const reading = h.manager.update(); h.manager.dispose()
  const before = JSON.stringify(h.s)
  pending.reject(failure(500)); await reading
  assert.ok(JSON.stringify(h.s) === before, 'No messages or Vue state may change after unmount.')
})
