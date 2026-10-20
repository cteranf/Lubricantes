import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { parse, compileTemplate } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as Vue from 'vue'
import { renderToString } from '@vue/server-renderer'

// Exercise the real setup functions and compiled template; only transport/layout are fixtures.
const { descriptor } = parse(readFileSync(new URL('../../resources/js/views/admin/PaymentSettings.vue', import.meta.url), 'utf8'))
function removeImports(code, context, resolveImport) {
  const imports = parseJs(code, { sourceType: 'module' }).program.body.filter(node => node.type === 'ImportDeclaration')
  for (const declaration of imports.reverse()) {
    for (const specifier of declaration.specifiers) {
      context[specifier.local.name] = resolveImport(specifier.imported?.name)
    }
    code = code.slice(0, declaration.start) + code.slice(declaration.end)
  }
  return code
}
function screen(settings) {
  let response = settings
  let reject = false
  const unmount = []; const notices = []; const gets = []; const puts = []
  const context = { ...Vue, onMounted: () => {}, onBeforeUnmount: callback => unmount.push(callback), useToast: () => ({ add(value) { notices.push(value) } }) }
  context.api = {
    get: async () => gets.length ? gets.shift().promise : ({ data: response }),
    put: async () => {
      if (puts.length) return puts.shift().promise
      if (reject) throw { response: { data: { message: 'No se pudo guardar.' } } }
      return { data: { settings: response } }
    },
  }
  const setup = removeImports(descriptor.scriptSetup.content, {}, () => undefined)
  vm.runInNewContext(`${setup}\nglobalThis.subject = { form, confirmedGateway, mockIsActive, effectiveGatewayLabel, loading, saving, loadError, loadSettings, saveSettings };`, context)
  const renderContext = {}
  const template = compileTemplate({ source: descriptor.template.content, filename: 'PaymentSettings.vue', id: 'payment-settings-contract' })
  assert.ok(template.errors.length === 0, 'The real template must compile.')
  const renderCode = removeImports(template.code, renderContext, name => Vue[name]).replace('export function render', 'function render')
  vm.runInNewContext(`${renderCode}\nglobalThis.render = render;`, renderContext)
  const subject = context.subject
  return {
    ...subject,
    respond(value) { response = value },
    reject() { reject = true },
    deferGet() { const pending = deferred(); gets.push(pending); return pending },
    deferPut() { const pending = deferred(); puts.push(pending); return pending },
    unmount() { unmount.forEach(callback => callback()) },
    notices,
    async html() {
      const app = Vue.createSSRApp({ setup: () => subject, render: renderContext.render })
      app.component('AdminLayout', { setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) })
      app.component('AdminPageHeader', { setup: () => () => Vue.h('header') })
      return renderToString(app)
    },
  }
}
function deferred() {
  let resolve; let reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
const fixture = (overrides = {}) => ({ card_enabled: true, card_gateway: 'mock', mock_environment_allowed: true, active_gateway: 'mock', ...overrides })

test('confirmed permitted mock renders active using backend authority', async () => {
  const view = screen(fixture()); await view.loadSettings()
  assert.ok(view.mockIsActive.value === true)
  assert.ok((await view.html()).includes('Modo Simulación Visa activo'))
})
test('production-style disallowed mock is configured but never usable', async () => {
  const view = screen(fixture({ mock_environment_allowed: false, active_gateway: 'mercadopago' })); await view.loadSettings()
  const html = await view.html()
  assert.ok(!view.mockIsActive.value && html.includes('La simulación está configurada, pero no está disponible en este entorno.'))
  assert.ok(!html.includes('Modo Simulación Visa activo') && !html.includes('Simulación disponible exclusivamente'))
  assert.ok(html.includes('Gateway efectivo confirmado: Mercado Pago'))
})
test('effective different gateway cannot be labeled active mock', async () => {
  const view = screen(fixture({ active_gateway: 'mercadopago' })); await view.loadSettings()
  assert.ok(!view.mockIsActive.value && !(await view.html()).includes('Modo Simulación Visa activo'))
})
test('unsaved selection cannot change confirmed gateway and ordinary gateway UI remains', async () => {
  const view = screen(fixture({ card_gateway: 'mercadopago', active_gateway: 'mercadopago' })); await view.loadSettings()
  assert.ok((await view.html()).includes('Credenciales de Mercado Pago'))
  view.form.value.card_gateway = 'mock'
  const html = await view.html()
  assert.ok(!view.mockIsActive.value && view.effectiveGatewayLabel.value === 'Mercado Pago')
  assert.ok(html.includes('cambios sin guardar') && !html.includes('Modo Simulación Visa activo'))
})
test('failed save preserves confirmed backend state', async () => {
  const view = screen(fixture({ card_gateway: 'mercadopago', active_gateway: 'mercadopago' })); await view.loadSettings()
  view.form.value.card_gateway = 'mock'; view.reject(); await view.saveSettings()
  assert.ok(!view.mockIsActive.value && view.confirmedGateway.value.active === 'mercadopago' && !view.saving.value)
  assert.ok(!(await view.html()).includes('Modo Simulación Visa activo'))
})
test('successful save adopts backend authority and clears entered private credential', async () => {
  const view = screen(fixture({ card_gateway: 'mercadopago', active_gateway: 'mercadopago' })); await view.loadSettings()
  view.form.value.card_gateway = 'mock'; view.form.value.mercadopago_access_token = 'synthetic-private-input'
  view.respond(fixture()); await view.saveSettings()
  assert.ok(view.mockIsActive.value && view.form.value.mercadopago_access_token === '')
  const html = await view.html()
  assert.ok(html.includes('Modo Simulación Visa activo') && !html.includes('synthetic-private-input'), 'Private entered credentials must not render.')
})

test('generation: old GET cannot overwrite confirmed PUT', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const old = view.deferGet(); const loading = view.loadSettings()
  view.respond(fixture({ card_gateway: 'mercadopago', active_gateway: 'mercadopago' }))
  await view.saveSettings()
  old.resolve({ data: fixture() }); await loading
  assert.ok(view.confirmedGateway.value.active === 'mercadopago' && !view.mockIsActive.value)
})
test('generation: GET B owns state loading and errors after GET A', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const a = view.deferGet(); const first = view.loadSettings()
  const b = view.deferGet(); const second = view.loadSettings()
  a.reject({ response: { data: { message: 'Obsolete failure' } } }); await first
  assert.ok(view.loading.value && view.loadError.value === '' && view.notices.length === 0)
  b.resolve({ data: fixture({ active_gateway: 'mercadopago' }) }); await second
  assert.ok(!view.loading.value && view.confirmedGateway.value.active === 'mercadopago')
})
test('generation: later GET wins regardless of completion order', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const a = view.deferGet(); const first = view.loadSettings()
  const b = view.deferGet(); const second = view.loadSettings()
  b.resolve({ data: fixture({ active_gateway: 'mercadopago' }) }); await second
  a.resolve({ data: fixture() }); await first
  assert.ok(!view.loading.value && view.confirmedGateway.value.active === 'mercadopago')
})
test('generation: GET requested during PUT is blocked and later GET can reload', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const put = view.deferPut(); const saving = view.saveSettings()
  await view.loadSettings()
  put.resolve({ data: { settings: fixture({ active_gateway: 'mercadopago' }) } }); await saving
  assert.ok(view.confirmedGateway.value.active === 'mercadopago')
  view.respond(fixture()); await view.loadSettings()
  assert.ok(view.mockIsActive.value && !view.loading.value)
})
test('generation: older PUT cannot overwrite a newer save or reset saving', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const a = view.deferPut(); const first = view.saveSettings()
  const b = view.deferPut(); const second = view.saveSettings()
  a.reject({ response: { data: { message: 'Obsolete save' } } }); await first
  assert.ok(view.saving.value && view.notices.length === 0)
  b.resolve({ data: { settings: fixture({ active_gateway: 'mercadopago' }) } }); await second
  assert.ok(!view.saving.value && view.confirmedGateway.value.active === 'mercadopago')
})
test('generation: old successful PUT after newer confirmation is ignored', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const a = view.deferPut(); const first = view.saveSettings()
  const b = view.deferPut(); const second = view.saveSettings()
  b.resolve({ data: { settings: fixture({ active_gateway: 'mercadopago' }) } }); await second
  a.resolve({ data: { settings: fixture() } }); await first
  assert.ok(!view.saving.value && view.confirmedGateway.value.active === 'mercadopago')
})
test('generation: unmount invalidates GET and PUT without visible errors', async () => {
  const view = screen(fixture()); await view.loadSettings()
  const a = view.deferGet(); const first = view.loadSettings()
  const b = view.deferPut(); const second = view.saveSettings()
  const before = JSON.stringify(view.confirmedGateway.value)
  view.unmount()
  a.resolve({ data: fixture({ active_gateway: 'mercadopago' }) })
  b.reject({ response: { data: { message: 'Unmounted failure' } } })
  await Promise.all([first, second])
  assert.ok(JSON.stringify(view.confirmedGateway.value) === before && view.notices.length === 0 && view.loadError.value === '')
})
