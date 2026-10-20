const filterNames = ['order', 'payment_method', 'refund_status', 'date_from', 'date_to']
export const emptyFilters = () => ({ order: '', payment_method: '', refund_status: '', date_from: '', date_to: '', per_page: 20 })

export function refundParams(filters, page = 1) {
  const params = {}
  for (const name of filterNames) {
    if (typeof filters[name] === 'string' && filters[name] !== '') params[name] = filters[name]
  }
  const size = Number(filters.per_page)
  params.per_page = Number.isInteger(size) && size > 0 ? Math.min(size, 20) : 20
  params.page = Number.isInteger(Number(page)) && Number(page) > 0 ? Number(page) : 1
  return params
}

export function safeRefundError(error) {
  switch (error?.response?.status) {
    case 401: return 'La sesión venció. Vuelve a iniciar sesión.'
    case 403: return 'No tienes autorización para esta operación.'
    case 404: return 'El recurso ya no está disponible.'
    case 409: return 'El estado cambió o requiere actualización. Actualiza la lectura antes de continuar.'
    case 422: return 'Revisa los campos y filtros autorizados.'
    case 429: return 'Hay demasiadas solicitudes. Espera y actualiza manualmente.'
    default: return 'No se pudo obtener confirmación. Actualiza la lectura antes de continuar.'
  }
}

// Decimal text is never converted to a floating point amount.
export function refundMoney(amount, currency) {
  if (typeof amount !== 'string' || !/^-?\d+\.\d{2}$/.test(amount) || !/^[A-Z]{3}$/.test(currency || '')) return '—'
  const [integer, decimal] = amount.split('.')
  return `${currency} ${integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${decimal}`
}

export function refundLabel(row) {
  if (row?.requires_manual_review === true) return 'Requiere revisión manual'
  if (row?.can_mark_refund_pending === true) return 'Se puede iniciar devolución'
  if (row?.can_complete_refund === true) return 'Devolución pendiente · Se puede completar'
  if (row?.refund?.status === 'completed') return 'Devolución completada'
  if (row?.refund?.status === 'pending') return 'Devolución pendiente'
  return 'No accionable'
}

export function createRefundManager(api, reactive = value => value) {
  const state = reactive({
    filters: emptyFilters(), rows: [], meta: {}, listLoading: false, listError: '',
    selectedId: null, detail: null, detailStatus: 'empty', detailError: '',
    actionMode: '', reason: '', external_reference: '', confirmed: false,
    commandLoading: false, readbackPending: false, blocked: false,
    commandError: '', fieldError: '', notice: '', refreshWarning: '',
  })
  let alive = true
  let listSequence = 0
  let detailSequence = 0
  let listController
  let detailController
  let listFlight
  let detailFlight
  let requestedPage = 1
  let applied = emptyFilters()
  let draftContext = null
  let refreshEpoch = 0
  let operationEpoch = 0
  let operationContext = null
  let activeRefresh = null

  function contextFor(action) {
    return Object.freeze({ action, orderId: state.selectedId, refundId: action === 'complete' ? state.detail?.refund?.id ?? null : null })
  }

  function sameContext(first, second) {
    return first !== null && second !== null && first.action === second.action
      && first.orderId === second.orderId && first.refundId === second.refundId
  }

  function clearDraft() {
    draftContext = null
    state.reason = ''; state.external_reference = ''; state.confirmed = false; state.fieldError = ''
  }

  function invalidateRefresh() {
    refreshEpoch++
    activeRefresh = null
    if (alive) state.readbackPending = false
  }

  function can(mode) {
    return alive && state.detailStatus === 'loaded' && !state.blocked && !state.commandLoading
      && !state.readbackPending && state.detail?.requires_manual_review !== true
      && (mode === 'pending' ? state.detail?.can_mark_refund_pending === true
        : mode === 'complete' && state.detail?.can_complete_refund === true)
  }

  function load(page = state.meta.current_page || requestedPage) {
    invalidateRefresh()
    return requestList(page)
  }

  function requestList(page = state.meta.current_page || requestedPage) {
    if (!alive) return Promise.resolve(false)
    const params = refundParams(applied, page)
    requestedPage = params.page
    const key = JSON.stringify(params)
    if (listFlight?.key === key) return listFlight.promise
    listController?.abort()
    listController = new AbortController()
    const sequence = ++listSequence
    state.listLoading = true
    state.listError = ''
    const promise = (async () => {
      try {
        const { data } = await api.get('/treasury/refund-worklist', { params, signal: listController.signal })
        if (!alive || sequence !== listSequence) return false
        state.rows = data.data
        state.meta = data.meta
        return true
      } catch (error) {
        if (!alive || sequence !== listSequence) return false
        if (error?.code === 'ERR_CANCELED') return null
        state.rows = []
        state.meta = {}
        state.listError = safeRefundError(error)
        return false
      } finally {
        if (alive && sequence === listSequence) { state.listLoading = false; listFlight = null }
      }
    })()
    listFlight = { key, promise }
    return promise
  }

  function apply() {
    applied = { ...state.filters }
    return load(1)
  }

  function clear() { Object.assign(state.filters, emptyFilters()); return apply() }

  function refreshDetail() {
    invalidateRefresh()
    return requestDetail()
  }

  function requestDetail() {
    if (detailFlight?.orderId === state.selectedId) return detailFlight.promise
    const orderId = state.selectedId
    const promise = acquireDetail()
    detailFlight = { orderId, promise }
    promise.finally(() => { if (detailFlight?.promise === promise) detailFlight = null })
    return promise
  }

  async function acquireDetail() {
    if (!alive || state.selectedId == null) return false
    const orderId = state.selectedId
    detailController?.abort()
    detailController = new AbortController()
    const sequence = ++detailSequence
    state.detail = null
    state.detailStatus = 'loading'
    state.detailError = ''
    try {
      const { data } = await api.get(`/treasury/orders/${orderId}/refund-state`, { signal: detailController.signal })
      if (!alive || sequence !== detailSequence || orderId !== state.selectedId) return false
      state.detail = data.data
      state.detailStatus = 'loaded'
      if (draftContext && !sameContext(draftContext, contextFor(draftContext.action))) {
        clearDraft()
        state.actionMode = ''
      }
      state.blocked = false
      return true
    } catch (error) {
      if (!alive || sequence !== detailSequence) return false
      if (error?.code === 'ERR_CANCELED') return null
      state.detailStatus = error?.response?.status === 404 ? 'missing' : error?.response?.status === 409 ? 'conflict' : 'error'
      state.detailError = safeRefundError(error)
      return false
    }
  }

  function openDetail(row) {
    if (state.commandLoading || state.actionMode) return Promise.resolve(false)
    invalidateRefresh()
    if (state.selectedId !== row.order_id) clearDraft()
    state.selectedId = row.order_id
    state.blocked = true
    state.commandError = ''
    state.notice = ''
    state.refreshWarning = ''
    return requestDetail()
  }

  function closeDetail() {
    if (state.commandLoading || state.actionMode) return
    invalidateRefresh()
    clearDraft()
    detailController?.abort()
    detailSequence++
    detailFlight = null
    state.selectedId = null
    state.detail = null
    state.detailStatus = 'empty'
  }

  function openAction(mode) {
    if (!can(mode)) return
    const context = contextFor(mode)
    if (!sameContext(draftContext, context)) clearDraft()
    draftContext = context
    state.actionMode = mode
    state.confirmed = false
    state.commandError = ''
    state.fieldError = ''
  }

  function closeAction() {
    if (!state.commandLoading) { state.actionMode = ''; clearDraft() }
  }

  function isCurrentRefresh(cycle, requests) {
    return alive && activeRefresh === cycle && cycle.epoch === refreshEpoch
      && cycle.orderId === state.selectedId && cycle.operation === operationContext
      && requests.list === listSequence && requests.detail === detailSequence
  }

  async function runRefresh(source) {
    const cycle = Object.freeze({ epoch: ++refreshEpoch, orderId: state.selectedId, operation: operationContext, source })
    activeRefresh = cycle
    state.readbackPending = source === 'command'
    const detailRead = state.selectedId == null ? Promise.resolve(true) : requestDetail()
    const listRead = requestList()
    let requests = Object.freeze({ list: listSequence, detail: detailSequence })
    let [detailOK, listOK] = await Promise.all([detailRead, listRead])
    if (!isCurrentRefresh(cycle, requests)) return false
    if (source === 'command' && listOK && state.meta.current_page > state.meta.last_page) {
      const corrected = requestList(state.meta.last_page)
      requests = Object.freeze({ list: listSequence, detail: detailSequence })
      listOK = await corrected
    }
    if (!isCurrentRefresh(cycle, requests)) return false
    state.readbackPending = false
    if (detailOK !== null && listOK !== null) {
      state.refreshWarning = detailOK && listOK ? '' : source === 'command'
        ? 'La operación fue exitosa. Actualiza la lectura para ver el estado vigente.'
        : 'Actualiza la lectura para ver el estado vigente.'
    }
    return detailOK === true && listOK === true
  }

  function update() {
    if (state.commandLoading) return Promise.resolve(false)
    return runRefresh('manual')
  }

  async function submit() {
    const mode = state.actionMode
    if (!state.confirmed || !can(mode)) return false
    if (!sameContext(draftContext, contextFor(mode))) {
      clearDraft(); state.actionMode = ''; state.blocked = true
      state.commandError = 'Actualiza el estado y vuelve a abrir la acción.'
      return false
    }
    const field = mode === 'pending' ? 'reason' : 'external_reference'
    if (typeof state[field] !== 'string' || state[field] === '') return false
    const orderId = state.selectedId
    const refundId = state.detail?.refund?.id
    if (mode === 'complete' && refundId == null) return false
    state.commandLoading = true
    invalidateRefresh()
    operationEpoch++
    operationContext = Object.freeze({ ...draftContext, sequence: operationEpoch })
    state.commandError = ''
    state.fieldError = ''
    // Invalidate pending reads before the command: none may restore an old action.
    listController?.abort(); listSequence++; listFlight = null; state.listLoading = false
    detailController?.abort(); detailSequence++; detailFlight = null
    try {
      if (mode === 'pending') await api.post(`/treasury/orders/${orderId}/refunds`, { reason: state.reason })
      else await api.post(`/treasury/payment-refunds/${refundId}/complete`, { external_reference: state.external_reference })
    } catch (error) {
      if (!alive) return false
      const status = error?.response?.status
      state.commandError = safeRefundError(error)
      if (status === 422) {
        const field = mode === 'pending' ? 'reason' : 'external_reference'
        if (Array.isArray(error?.response?.data?.errors?.[field])) {
          state.fieldError = mode === 'pending' ? 'Revisa el motivo: entre 1 y 1000 caracteres.' : 'Revisa la referencia: una línea sin controles, entre 1 y 255 bytes.'
        }
      } else {
        state.blocked = true
        if (status === 404) { state.detail = null; state.detailStatus = 'missing'; state.actionMode = ''; clearDraft() }
      }
      state.commandLoading = false
      return false
    }
    if (!alive) return true
    state.commandLoading = false
    state.actionMode = ''
    clearDraft()
    state.notice = mode === 'pending' ? 'Reembolso marcado como pendiente.' : 'Reembolso completado correctamente.'
    await runRefresh('command')
    return true
  }

  function dispose() {
    invalidateRefresh()
    alive = false; listSequence++; detailSequence++
    listController?.abort(); detailController?.abort()
    clearDraft()
  }

  return { state, can, load, apply, clear, openDetail, closeDetail, refreshDetail, openAction, closeAction, submit, update, dispose }
}
