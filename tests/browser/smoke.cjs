// Run only against the guarded test-web service, never the normal demo DB.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const target = process.env.BGM_URL || 'http://127.0.0.1:8089/';
  // Health endpoint has no data-pruning/session middleware or application writes.
  const response = await fetch(new URL('/up', target));
  assert.equal(response.headers.get('x-bgm-test-database'), 'pgsql:youtube_bgm_test', 'Refusing browser writes: target is not the verified PostgreSQL test server');
  assert.equal(response.status, 200);
  const browser = await chromium.launch({ headless: true, executablePath: process.env.BGM_CHROME || undefined, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(target);
    await page.getByText('架空データのデモ', { exact: true }).waitFor();
    const search = page.locator('form[action$="/search"]');
    await search.locator('[name=q]').fill('読書 ピアノ 雨');
    await search.locator('[name=limit]').fill('1');
    await search.getByRole('button').click();
    await page.getByRole('heading', { name: '取得範囲と結果' }).waitFor();
    assert((await page.locator('body').innerText()).includes('続きあり・部分取得'));
    const resume = page.locator('form[action$="/resume"]');
    await resume.locator('[name=limit]').fill('20');
    await resume.getByRole('button').click();
    await page.getByText('APIの続きトークンなし。公開され取得可能な応答範囲です。', { exact: true }).waitFor();
    await page.getByRole('link', { name: '候補一覧へ戻る' }).click();
    assert.equal(await page.locator('.card').count(), 4);
    const first = page.locator('.card').filter({ has: page.getByRole('link', { name: '架空：雨音とピアノ・読書用', exact: true }) });
    await first.locator('select[name=watched]').selectOption('1');
    await first.locator('input[name=purpose]').fill('ブラウザー確認：同じ尺の動画を聴き比べる');
    await first.getByRole('button', { name: '設定と目的を保存' }).click();
    await page.getByRole('heading', { name: 'ウォッチ中の対象' }).waitFor();
    for (const title of ['架空：雨音とピアノ・読書用', '架空：雨音とピアノ・静かな机']) {
      await page.locator('.card').filter({ has: page.getByRole('link', { name: title, exact: true }) }).locator('[name="ids[]"]').check();
    }
    await page.getByRole('button', { name: '選んだ動画を並べる' }).click();
    await page.getByRole('heading', { name: '公開情報を並べる' }).waitFor();
    assert.equal(await page.locator('.compare-grid > article').count(), 2);
    await page.locator('input[name=purpose]').fill('ブラウザー確認：同条件で結果が異なる動画');
    await page.getByRole('button', { name: '比較対象と目的を保存する' }).click();
    await page.getByText('ブラウザー確認：同条件で結果が異なる動画', { exact: false }).waitFor();
    await page.screenshot({ path: '/tmp/bgm-comparison.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile comparison overflow');
    await page.getByRole('link', { name: '候補一覧へ戻る' }).click();
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile discovery overflow');
    await page.screenshot({ path: '/tmp/bgm-discovery-mobile.png', fullPage: true });
    assert.deepEqual(errors, []);
    console.log('PASS: demo search, pagination, watch, compare, purpose save, desktop/mobile layout; no page errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
