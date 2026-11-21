import { createRequire } from 'node:module'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { sanitize } from './decode-frame.mjs'

const require = createRequire(new URL('../lab/package.json', import.meta.url))
const artifacts = fileURLToPath(new URL('../lab/artifacts/', import.meta.url))
const documentPath = `${artifacts}/conversion-probe.docx`
const output = fileURLToPath(new URL('../results/conversion-probe.json', import.meta.url))
const text = (value, marks) => ({ type: 'text', text: value, ...(marks ? { marks } : {}) })
const paragraph = (value, attrs) => ({ type: 'paragraph', ...(attrs ? { attrs } : {}), content: [text(value)] })
const sample = {
  type: 'doc', content: [
    { type: 'heading', attrs: { level: 2 }, content: [text('Synthetic Heading')] },
    { type: 'paragraph', attrs: { textAlign: 'center', indent: 24, spacingBefore: 12, spacingAfter: 6 }, content: [
      text('PlainSample '), text('BoldSample', [{ type: 'bold' }]), text(' '),
      text('LinkSample', [{ type: 'link', attrs: { href: 'https://example.test/conversion' } }]),
    ] },
    { type: 'bulletList', content: ['BulletOne', 'BulletTwo'].map(value => ({ type: 'listItem', content: [paragraph(value)] })) },
    { type: 'orderedList', attrs: { start: 3 }, content: ['OrderedThree', 'OrderedFour'].map(value => ({ type: 'listItem', content: [paragraph(value)] })) },
    { type: 'table', attrs: { width: 320 }, content: [
      { type: 'tableRow', content: [
        { type: 'tableHeader', attrs: { colspan: 1, rowspan: 1, colwidth: [160], background: '#ddeeff' }, content: [paragraph('HeaderOne')] },
        { type: 'tableHeader', attrs: { colspan: 1, rowspan: 1, colwidth: [160] }, content: [paragraph('HeaderTwo')] },
      ] },
      { type: 'tableRow', attrs: { height: 24, heightRule: 'atLeast' }, content: [
        { type: 'tableCell', attrs: { colspan: 1, rowspan: 1, colwidth: [160], verticalAlign: 'middle' }, content: [paragraph('CellOne')] },
        { type: 'tableCell', attrs: { colspan: 1, rowspan: 1, colwidth: [160] }, content: [paragraph('CellTwo')] },
      ] },
    ] },
    { type: 'paragraph', content: [text('LineOne'), { type: 'hardBreak' }, text('LineTwo')] },
  ],
}

const xmlInspection = String.raw`
import sys, json, zipfile, hashlib
import xml.etree.ElementTree as ET
path=sys.argv[1]
ns={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main','r':'http://schemas.openxmlformats.org/officeDocument/2006/relationships','rel':'http://schemas.openxmlformats.org/package/2006/relationships'}
w=lambda name:'{'+ns['w']+'}'+name
checks=[]
def check(name,condition): checks.append({'name':name,'passed':bool(condition)})
def attrs(element): return {name.split('}')[-1]:value for name,value in element.attrib.items()}
with zipfile.ZipFile(path) as archive:
 names=archive.namelist()
 check('zip-has-document-styles-numbering-relationships',all(name in names for name in ['word/document.xml','word/styles.xml','word/numbering.xml','word/_rels/document.xml.rels','[Content_Types].xml']))
 document=ET.fromstring(archive.read('word/document.xml'))
 relationships=ET.fromstring(archive.read('word/_rels/document.xml.rels'))
 numbering=ET.fromstring(archive.read('word/numbering.xml'))
 paragraphs=document.findall('.//w:p',ns)
 runs=document.findall('.//w:r',ns)
 heading_styles=[e.get(w('val')) for e in document.findall('.//w:pStyle',ns)]
 bold_text=[''.join(r.itertext()) for r in runs if r.find('w:rPr/w:b',ns) is not None]
 links=[{'id':e.get('Id'),'target':e.get('Target'),'mode':e.get('TargetMode')} for e in relationships if e.get('Type','').endswith('/hyperlink')]
 tables=document.findall('.//w:tbl',ns)
 table_rows=document.findall('.//w:tbl/w:tr',ns)
 table_cells=document.findall('.//w:tbl/w:tr/w:tc',ns)
 check('heading-maps-to-Heading2', 'Heading2' in heading_styles)
 check('bold-run-contains-own-fixture', any('BoldSample' in value for value in bold_text))
 check('hyperlink-is-external-relationship', any(link['target']=='https://example.test/conversion' and link['mode']=='External' for link in links))
 check('hyperlink-element-references-relationship', any(e.get('{'+ns['r']+'}id') in {link['id'] for link in links} for e in document.findall('.//w:hyperlink',ns)))
 check('list-items-carry-numbering', len(document.findall('.//w:pPr/w:numPr',ns))==4)
 check('numbering-defines-bullet-and-decimal', {'bullet','decimal'} <= {e.get(w('val')) for e in numbering.findall('.//w:numFmt',ns)})
 check('table-two-rows-four-cells', len(tables)==1 and len(table_rows)==2 and len(table_cells)==4)
 check('table-has-column-grid', len(document.findall('.//w:tblGrid/w:gridCol',ns))==2)
 check('hard-break-is-present', len(document.findall('.//w:br',ns))>=1)
 check('paragraph-centered',any(e.get(w('val'))=='center' for e in document.findall('.//w:jc',ns)))
 check('paragraph-indent-24px-becomes-360twips',any(e.get(w('left'))=='360' for e in document.findall('.//w:ind',ns)))
 check('paragraph-spacing-px-converts-to-twips',any(e.get(w('before'))=='180' and e.get(w('after'))=='90' for e in document.findall('.//w:spacing',ns)))
 check('cell-background-normalized-to-hex',any(e.get(w('fill'))=='DDEEFF' for e in document.findall('.//w:shd',ns)))
 check('cell-middle-align-maps-to-OOXML-center',any(e.get(w('val'))=='center' for e in document.findall('.//w:vAlign',ns)))
 check('row-height-24px-becomes-360twips-atLeast',any(e.get(w('val'))=='360' and e.get(w('hRule'))=='atLeast' for e in document.findall('.//w:trHeight',ns)))
 all_text=''.join(e.text or '' for e in document.findall('.//w:t',ns))
 check('all-synthetic-text-survived', all(value in all_text for value in ['Synthetic Heading','PlainSample','BoldSample','LinkSample','BulletOne','BulletTwo','OrderedThree','OrderedFour','HeaderOne','HeaderTwo','CellOne','CellTwo','LineOne','LineTwo']))
 print(json.dumps({'checks':checks,'parts':names,'partSizes':{name:archive.getinfo(name).file_size for name in names},'summary':{'paragraphs':len(paragraphs),'runs':len(runs),'headingStyles':heading_styles,'hyperlinks':links,'numberedParagraphs':len(document.findall('.//w:pPr/w:numPr',ns)),'tables':len(tables),'rows':len(table_rows),'cells':len(table_cells),'text':all_text,'paragraphIndents':[attrs(e) for e in document.findall('.//w:ind',ns)],'paragraphSpacing':[attrs(e) for e in document.findall('.//w:spacing',ns)],'cellShading':[attrs(e) for e in document.findall('.//w:shd',ns)],'cellVerticalAlign':[attrs(e) for e in document.findall('.//w:vAlign',ns)],'rowHeights':[attrs(e) for e in document.findall('.//w:trHeight',ns)]}},ensure_ascii=False))
`

const originalFetch = globalThis.fetch
const requests = []
globalThis.fetch = async input => {
  requests.push(sanitize({ url: typeof input === 'string' ? input : input?.url ?? String(input) }))
  throw new Error('Network prohibited by offline conversion probe')
}
const result = { runAt: new Date().toISOString(), scope: 'local-Node-export-only; network-prohibited', sample, requests, status: 'running' }
try {
  const { exportDocx } = require('@tiptap-pro/extension-export-docx')
  const registry = require('@tiptap-pro/extension-convert-kit/ooxml-registry')
  result.registry = registry.ooxmlAttributes.map(({ kind, attr, unit, nodes, mark, group }) => ({ kind, attr, unit, nodes, mark, group }))
  try {
    const { getSchema } = require('@tiptap/core')
    const { ConvertKit } = require('@tiptap-pro/extension-convert-kit')
    const schema = getSchema([ConvertKit])
    result.convertKitSchema = {
      nodes: Object.fromEntries(Object.entries(schema.nodes).map(([name, type]) => [name, Object.keys(type.spec.attrs ?? {})])),
      marks: Object.fromEntries(Object.entries(schema.marks).map(([name, type]) => [name, Object.keys(type.spec.attrs ?? {})])),
    }
  } catch (error) {
    result.convertKitSchemaError = sanitize({ name: error.name, message: error.message })
    // A documented option disables the CJS default-import compatibility problem.
    const { getSchema } = require('@tiptap/core')
    const { ConvertKit } = require('@tiptap-pro/extension-convert-kit')
    const schema = getSchema([ConvertKit.configure({ textAlign: false })])
    result.convertKitSchema = {
      configuredOptions: { textAlign: false },
      nodes: Object.fromEntries(Object.entries(schema.nodes).map(([name, type]) => [name, Object.keys(type.spec.attrs ?? {})])),
      marks: Object.fromEntries(Object.entries(schema.marks).map(([name, type]) => [name, Object.keys(type.spec.attrs ?? {})])),
    }
  }
  result.unitChecks = [
    { name: '96px-is-1440twips', passed: registry.pixelsToTwips(96) === 1440 },
    { name: '12pt-is-240twips', passed: registry.pointsToTwips(12) === 240 },
  ]
  result.warnings = []
  const generated = await exportDocx({
    document: sample, exportType: 'buffer', customNodes: [], styleOverrides: {},
    onWarning(warning) { result.warnings.push(sanitize({ code: warning.code, message: warning.message })) },
  })
  if (!Buffer.isBuffer(generated)) throw new Error('Export did not return a Buffer')
  await mkdir(artifacts, { recursive: true })
  await writeFile(documentPath, generated)
  result.document = { path: 'lab/artifacts/conversion-probe.docx', bytes: generated.length, sha256: createHash('sha256').update(generated).digest('hex') }
  const inspected = spawnSync('python3', ['-c', xmlInspection, documentPath], { encoding: 'utf8', maxBuffer: 1024 * 1024 })
  if (inspected.status !== 0) throw new Error(`OOXML inspection failed: ${inspected.stderr}`)
  result.ooxml = JSON.parse(inspected.stdout)
  result.packages = Object.fromEntries(await Promise.all(['@tiptap-pro/extension-export-docx','@tiptap-pro/extension-convert-kit','docx'].map(async name => [name, JSON.parse(await readFile(new URL(`../lab/node_modules/${name}/package.json`, import.meta.url), 'utf8')).version])))
  const revisionAttrs = (id, type, extra = {}) => ({ id, type, userId: 'synthetic-author', createdAt: '2026-11-21T00:00:00.000Z', userMetadata: { name: 'Synthetic Author' }, ...extra })
  const extendedSamples = {
    comments: {
      document: { type: 'doc', content: [{ type: 'paragraph', content: [text('Synthetic Comment Anchor', [{ type: 'inlineThread', attrs: { 'data-thread-id': 'synthetic-thread' } }])] }] },
      comments: { threads: [{ id: 'synthetic-thread', comments: [
        { id: 'synthetic-comment-root', content: { type: 'doc', content: [paragraph('Synthetic Root Comment')] }, data: { author: 'Synthetic Author', originalWId: '7', originalDurableId: '000000AF' }, createdAt: '2026-11-21T00:00:00Z' },
        { id: 'synthetic-comment-reply', content: { type: 'doc', content: [paragraph('Synthetic Reply Comment')] }, data: { author: 'Synthetic Reviewer', originalWId: '8', originalDurableId: '000000BF' }, createdAt: '2026-11-21T00:01:00Z' },
      ] }] },
    },
    revisions: { document: { type: 'doc', content: [{ type: 'paragraph', content: [
      text('SyntheticInserted', [{ type: 'suggestion', attrs: revisionAttrs('synthetic-add', 'add') }]),
      text('SyntheticDeleted', [{ type: 'suggestion', attrs: revisionAttrs('synthetic-delete', 'delete') }]),
      text('SyntheticBoldProposal', [{ type: 'bold' }, { type: 'suggestion', attrs: revisionAttrs('synthetic-format', 'markChange', { markChanges: [{ operation: 'added', markName: 'bold', markAttrs: {} }] }) }]),
    ] }] } },
  }
  const extendedInspection = String.raw`
import sys,json,zipfile,xml.etree.ElementTree as ET
ns={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main','w15':'http://schemas.microsoft.com/office/word/2012/wordml','w16cid':'http://schemas.microsoft.com/office/word/2016/wordml/cid'}
w=lambda name:'{'+ns['w']+'}'+name
checks=[]
def check(name,value):checks.append({'name':name,'passed':bool(value)})
with zipfile.ZipFile(sys.argv[1]) as archive:
 names=archive.namelist();doc=ET.fromstring(archive.read('word/document.xml'))
 if sys.argv[2]=='comments':
  comments=ET.fromstring(archive.read('word/comments.xml'))
  ids=[c.get(w('id')) for c in comments.findall('w:comment',ns)]
  texts=[''.join(c.itertext()) for c in comments.findall('w:comment',ns)]
  ranges=[c.get(w('id')) for c in doc.findall('.//w:commentRangeStart',ns)]
  ends=[c.get(w('id')) for c in doc.findall('.//w:commentRangeEnd',ns)]
  refs=[c.get(w('id')) for c in doc.findall('.//w:commentReference',ns)]
  check('comments-export-two-own-comments',len(ids)==2 and any('Synthetic Root Comment' in t for t in texts) and any('Synthetic Reply Comment' in t for t in texts))
  check('comments-preserve-imported-word-ids',set(ids)=={'7','8'})
  check('comment-anchor-range-is-balanced',ranges==ends and '7' in ranges)
  check('comment-reference-points-to-known-id',bool(refs) and set(refs)<=set(ids))
  summary={'commentIds':ids,'rangeStarts':ranges,'rangeEnds':ends,'references':refs,'commentTexts':texts,'threadedMetadataParts':[n for n in names if 'comment' in n.lower()]}
 else:
  insertions=doc.findall('.//w:ins',ns);deletions=doc.findall('.//w:del',ns)
  check('tracked-insertion-native-word-ins',any('SyntheticInserted' in ''.join(e.itertext()) for e in insertions))
  check('tracked-deletion-native-word-del',any('SyntheticDeleted' in ''.join(e.itertext()) for e in deletions))
  check('tracked-revisions-retain-author',all(e.get(w('author'))=='Synthetic Author' for e in insertions+deletions))
  check('deleted-text-uses-delText',any(e.text=='SyntheticDeleted' for e in doc.findall('.//w:delText',ns)))
  summary={'insertions':len(insertions),'deletions':len(deletions),'formatRevisions':len(doc.findall('.//w:rPrChange',ns)),'paragraphRevisions':len(doc.findall('.//w:pPrChange',ns)),'revisionDates':[e.get(w('date')) for e in insertions+deletions],'boldProposalStillBold':any('SyntheticBoldProposal' in ''.join(r.itertext()) and r.find('w:rPr/w:b',ns) is not None for r in doc.findall('.//w:r',ns))}
 print(json.dumps({'checks':checks,'summary':summary,'parts':names},ensure_ascii=False))
`
  result.extended = []
  for (const [name, options] of Object.entries(extendedSamples)) {
    const binary = await exportDocx({ ...options, exportType: 'buffer', customNodes: [], styleOverrides: {} })
    const path = `${artifacts}/conversion-${name}.docx`
    await writeFile(path, binary)
    const inspected = spawnSync('python3', ['-c', extendedInspection, path, name], { encoding: 'utf8', maxBuffer: 1024 * 1024 })
    if (inspected.status !== 0) throw new Error(`Extended OOXML inspection failed: ${inspected.stderr}`)
    result.extended.push({ name, sample: options, document: { path: `lab/artifacts/conversion-${name}.docx`, bytes: binary.length, sha256: createHash('sha256').update(binary).digest('hex') }, ...JSON.parse(inspected.stdout) })
  }
  result.networkChecks = [{ name: 'export-never-called-fetch', passed: requests.length === 0 }]
  result.status = [...result.unitChecks, ...result.ooxml.checks, ...result.extended.flatMap(item => item.checks), ...result.networkChecks].every(check => check.passed) ? 'passed' : 'failed'
} catch (error) {
  result.status = 'failed-or-blocked'
  result.error = sanitize({ name: error.name, message: error.message })
} finally {
  globalThis.fetch = originalFetch
  result.finishedAt = new Date().toISOString()
  await mkdir(fileURLToPath(new URL('../results/', import.meta.url)), { recursive: true })
  await writeFile(output, JSON.stringify(sanitize(result), null, 2) + '\n')
  console.log(JSON.stringify({ status: result.status, document: result.document, checks: [...result.unitChecks ?? [], ...result.ooxml?.checks ?? [], ...result.extended?.flatMap(item => item.checks) ?? [], ...result.networkChecks ?? []], error: result.error, output }, null, 2))
  if (result.status !== 'passed') process.exitCode = 1
}
