import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { parse as parseVue } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import { ref, computed } from 'vue'
import { toDateInput, parseCivilDate, hydrateCivilDates, civilDatePayload } from '../../resources/js/utils/civilDate.mjs'

const valid = [
  ['2028-02-29', '2028-02-29'], ['2000-02-29', '2000-02-29'],
  ['2028-02-29T00:00:00.000000Z', '2028-02-29'],
  ['2028-02-29T05:00:00.000000Z', '2028-02-29'],
  ['2028-02-29 00:00:00', '2028-02-29'],
  ['2028-02-29T00:30:00+14:00', '2028-02-29'],
  ['2028-02-29T23:30:00-05:00', '2028-02-29'],
]
for (const [value, expected] of valid) {
  test(`civil date: preserves ${value}`, () => assert.ok(toDateInput(value) === expected))
}
test('civil date: empty, invalid and non-string values never receive a fallback', () => {
  for (const value of [null, undefined, '', 0, {}, [], '2027-02-29', '1900-02-29', '0000-01-01', '2028-00-01', '2028-13-01', '2028-04-31', '2028-01-00', '2028-01-32', '2028-2-1', '2028-01-01 garbage', '2028-01-01T25:00:00Z', '2028-01-01T00:61:00Z', '2028-01-01T00:00:61Z', '2028-01-01T00:00:00+14:01', '2028-01-01T00:00:00+15:00', '2028-01-01T00:00:00Zsuffix']) {
    assert.ok(toDateInput(value) === '', 'Invalid civil dates must remain empty, never repaired.')
  }
})

// Execute the actual SFC setup/open/save functions with Vue refs and a mock transport.
// Only imports are removed by AST positions; form logic is never reimplemented here.
function screen(name) {
  const source = readFileSync(new URL(`../../resources/js/views/admin/${name}.vue`, import.meta.url), 'utf8')
  const { descriptor } = parseVue(source)
  let code = descriptor.scriptSetup.content
  const imports = parseJs(code, { sourceType: 'module' }).program.body.filter(node => node.type === 'ImportDeclaration')
  for (const declaration of imports.reverse()) code = code.slice(0, declaration.start) + code.slice(declaration.end)
  const requests = []; const notices = []
  let rejected = false
  const api = {
    get: async () => ({ data: { data: [], total: 0, current_page: 1 } }),
    put: async (url, payload) => {
      requests.push({ method: 'put', url, payload: structuredClone({ ...payload }) })
      if (rejected) throw { response: { status: 422, data: { message: 'Revisa la fecha.', errors: { license_expires_at: ['Fecha inválida.'] } } } }
    },
    post: async (url, payload) => requests.push({ method: 'post', url, payload: { ...payload } }),
  }
  const context = { ref, computed, onMounted: () => {}, api, toDateInput, parseCivilDate, hydrateCivilDates, civilDatePayload, useToast: () => ({ add: notice => notices.push(notice) }) }
  vm.runInNewContext(`${code}\n;globalThis.subject = { open, save, form, modal, saving, dateStates, correctDate, clearRecordedDate };`, context)
  return { ...context.subject, requests, notices, source, reject: () => { rejected = true } }
}

test('integrity: parser distinguishes empty valid invalid without retaining the received value', () => {
  for (const value of [null, undefined, '']) {
    const result = parseCivilDate(value)
    assert.ok(result.state === 'empty' && result.value === '' && Object.isFrozen(result))
  }
  const validDate = parseCivilDate('2028-02-29T05:00:00.000000Z')
  assert.ok(validDate.state === 'valid' && validDate.value === '2028-02-29')
  for (const value of ['synthetic-unrecognized-date', '2100-02-29', '2028-02-30', true, false, {}, [], 0]) {
    const result = parseCivilDate(value)
    assert.ok(result.state === 'invalid' && result.value === '', 'Nonempty invalid values must not become legitimate empty dates.')
    assert.ok(Object.keys(result).sort().join(',') === 'state,value', 'Parser output must not retain private source data.')
  }
})

for (const [name, fields, base] of [
  ['DeliveryVehicles', ['soat_expires_at', 'technical_inspection_expires_at'], { id: 1, code: 'INTEGRITY-VEHICLE', vehicle_type: 'van', ownership_type: 'company' }],
  ['DeliveryDrivers', ['license_expires_at'], { id: 1, code: 'INTEGRITY-DRIVER', first_name: 'Fixture', last_name: 'Test', phone: '000000000' }],
]) {
  for (const field of fields) {
    const fixture = () => ({ ...base, ...Object.fromEntries(fields.map(key => [key, key === field ? 'synthetic-unrecognized-date' : '2029-03-15T00:00:00.000000Z'])) })
    test(`integrity: ${name} ${field} is omitted while other attributes and dates save`, async () => {
      const view = screen(name)
      view.open(fixture()); view.form.value.code = 'INTEGRITY-EDITED'
      assert.ok(view.form.value[field] === '' && view.dateStates.value[field] === 'invalid')
      await view.save()
      const payload = view.requests[0].payload
      assert.ok(!Object.hasOwn(payload, field), 'An unresolved date must be absent, not null empty or undefined.')
      assert.ok(Object.values(payload).every(value => value !== undefined))
      assert.ok(payload.code === 'INTEGRITY-EDITED')
      for (const other of fields.filter(key => key !== field)) assert.ok(payload[other] === '2029-03-15')
      assert.ok(view.source.includes(`:aria-invalid="dateStates.${field}`) && view.source.includes(`id="${field}-integrity"`))
    })
    test(`integrity: ${name} ${field} explicit valid correction is included`, async () => {
      const view = screen(name); view.open(fixture())
      view.correctDate(field, '2030-12-31'); await view.save()
      assert.ok(view.dateStates.value[field] === 'valid' && view.requests[0].payload[field] === '2030-12-31')
    })
    test(`integrity: ${name} ${field} empty input alone cannot clear but explicit removal can`, async () => {
      const view = screen(name); view.open(fixture())
      view.correctDate(field, ''); await view.save()
      assert.ok(!Object.hasOwn(view.requests[0].payload, field) && view.dateStates.value[field] === 'invalid')
      view.clearRecordedDate(field); await view.save()
      assert.ok(view.dateStates.value[field] === 'empty' && Object.hasOwn(view.requests[1].payload, field) && view.requests[1].payload[field] === '')
    })
    test(`integrity: ${name} ${field} new selection creation and reopening reset integrity`, () => {
      const view = screen(name); const original = fixture()
      view.open(original); view.modal.value = false
      assert.ok(view.requests.length === 0, 'Canceling must never persist changes.')
      view.open({ ...base, id: 2, ...Object.fromEntries(fields.map(key => [key, '2028-02-29'])) })
      fields.forEach(key => assert.ok(view.dateStates.value[key] === 'valid' && view.form.value[key] === '2028-02-29'))
      view.open()
      fields.forEach(key => assert.ok(view.dateStates.value[key] === 'empty' && view.form.value[key] === ''))
      view.open(original)
      assert.ok(view.dateStates.value[field] === 'invalid' && view.form.value[field] === '', 'Reopening must reevaluate the original record.')
    })
  }
}

for (const [name, fields, base] of [
  ['DeliveryVehicles', ['soat_expires_at', 'technical_inspection_expires_at'], { id: 1, code: 'FIXTURE-VEHICLE', vehicle_type: 'van', ownership_type: 'company', brand: 'Fixture', is_active: true, is_available: true }],
  ['DeliveryDrivers', ['license_expires_at'], { id: 1, code: 'FIXTURE-DRIVER', first_name: 'Fixture', last_name: 'Test', phone: '000000000', is_active: true, is_available: true }],
]) {
  test(`${name}: actual edit hydrates every date and saves unchanged civil values`, async () => {
    const view = screen(name)
    const original = { ...base }
    fields.forEach((field, index) => { original[field] = index === 0 ? '2028-02-29T05:00:00.000000Z' : '2029-03-15T00:00:00.000000Z' })
    view.open(original)
    fields.forEach((field, index) => {
      assert.ok(view.form.value[field] === (index === 0 ? '2028-02-29' : '2029-03-15'))
      assert.ok(view.source.includes(`v-model="form.${field}" type="date"`))
    })
    await view.save()
    const sent = view.requests[0]
    assert.ok(sent.method === 'put' && sent.url.endsWith('/1'))
    fields.forEach(field => assert.ok(sent.payload[field] === view.form.value[field]))
    Object.entries(base).forEach(([key, value]) => assert.ok(sent.payload[key] === value, 'Non-date fields must remain unchanged.'))
    assert.ok(original[fields[0]] === '2028-02-29T05:00:00.000000Z', 'Opening must not mutate the API row.')
  })
  test(`${name}: edited and cleared dates use the existing update payload`, async () => {
    const view = screen(name)
    view.open({ ...base, ...Object.fromEntries(fields.map(field => [field, '2028-02-29'])) })
    view.form.value[fields[0]] = '2030-12-31'
    await view.save()
    assert.ok(view.requests[0].payload[fields[0]] === '2030-12-31')
    view.form.value[fields[0]] = ''
    await view.save()
    assert.ok(view.requests[1].payload[fields[0]] === '')
  })
  test(`${name}: consecutive opens and reopens never retain a previous date`, () => {
    const view = screen(name)
    const a = { ...base, ...Object.fromEntries(fields.map(field => [field, '2028-02-29T00:00:00.000000Z'])) }
    const b = { ...base, id: 2, ...Object.fromEntries(fields.map(field => [field, null])) }
    view.open(a); view.form.value[fields[0]] = '2030-01-01'; view.modal.value = false
    view.open(b); fields.forEach(field => assert.ok(view.form.value[field] === ''))
    view.open(a); fields.forEach(field => assert.ok(view.form.value[field] === '2028-02-29'))
    view.open({ ...base }); fields.forEach(field => assert.ok(view.form.value[field] === ''))
  })
  test(`${name}: creating keeps empty dates and existing defaults`, async () => {
    const view = screen(name)
    view.open()
    fields.forEach(field => assert.ok(view.form.value[field] === ''))
    view.form.value.code = base.code
    await view.save()
    assert.ok(view.requests[0].method === 'post' && view.form.value.is_active && view.form.value.is_available)
  })
  test(`${name}: validation failures retain the form and existing error presentation`, async () => {
    const view = screen(name)
    view.open({ ...base, ...Object.fromEntries(fields.map(field => [field, '2028-02-29'])) })
    view.reject(); await view.save()
    assert.ok(view.modal.value && !view.saving.value && view.notices.length === 1)
    fields.forEach(field => assert.ok(view.form.value[field] === '2028-02-29'))
  })
}
