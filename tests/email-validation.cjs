const fs = require('node:fs')
const vm = require('node:vm')
const assert = require('node:assert/strict')
const src = fs.readFileSync('js/admin.js', 'utf8')
const line = src.split('\n').find(x => x.includes('const valid ='))
assert.ok(line, 'email regex must exist')
const regex = vm.runInNewContext(line.trim().replace(/^const valid = /, ''))
for (const email of ['firstname.lastname@example.com', 'first.last+tag@example.org', 'a_b@example.co.at']) {
  assert.equal(regex.test(email), true, 'must accept ' + email)
}
for (const email of ['invalid', 'foo@@example.com', 'foo bar@example.com', 'foo@example,com', 'foo@examplecom']) {
  assert.equal(regex.test(email), false, 'must reject ' + email)
}
console.log('PASS email input validation regression')
