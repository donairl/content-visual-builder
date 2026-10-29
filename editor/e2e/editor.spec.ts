import { expect, test, type Locator, type Page } from '@playwright/test';
import { ADMIN, AUTHOR, login } from './helpers';

const canvas = (page: Page) => page.locator('[data-cvb-root]');
const node = (page: Page, type: string) => canvas(page).locator(`.cvb-${type}`);

async function createPage(page: Page, title: string): Promise<number> {
  await page.goto('/editor/');
  await page.getByRole('button', { name: 'New page' }).click();
  await page.getByLabel('Title').fill(title);
  await page.getByRole('button', { name: 'Create & edit' }).click();
  await page.waitForURL(/\/editor\/edit\/\?page=\d+/);
  await expect(page.getByText('Start by adding a section')).toBeVisible();
  return Number(new URL(page.url()).searchParams.get('page'));
}

/** Palette tile; inserting a widget switches the panel to its settings, so reopen Elements first. */
async function tile(page: Page, name: string): Promise<Locator> {
  await page.getByRole('tab', { name: 'Elements' }).click();
  return page.getByRole('button', { name: new RegExp(`^${name}$`) });
}

async function waitSaved(page: Page) {
  await expect(page.getByRole('status').filter({ hasText: /Saved|All changes saved/ })).toBeVisible({ timeout: 10_000 });
}

/** Pointer drag with intermediate moves so dnd-kit's activation distance and hit-testing run. */
async function drag(page: Page, from: Locator, to: Locator, position: 'top' | 'center' | 'bottom' = 'center') {
  const a = (await from.boundingBox())!;
  const b = (await to.boundingBox())!;
  const y = position === 'top' ? b.y + 4 : position === 'bottom' ? b.y + b.height - 4 : b.y + b.height / 2;
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
  await page.mouse.down();
  await page.mouse.move(a.x + a.width / 2 + 10, a.y + a.height / 2 + 10, { steps: 3 });
  await page.mouse.move(b.x + b.width / 2, y, { steps: 12 });
  await expect(page.locator('.ed-drop-line, .ed-drop-box')).toBeVisible();
  await page.mouse.up();
  // dnd-kit swallows clicks for 50ms after a drop (so the drop is not also a click); a person
  // never clicks that fast, Playwright does.
  await page.waitForTimeout(100);
}

test.describe('editor', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ADMIN);
  });

  test('FR-01/02/04: create a page, drop widgets, edit inline and from the panel', async ({ page }) => {
    await createPage(page, 'Drag and drop');

    // Click-to-add a two-column section, then drag a heading into the first column.
    await page.getByRole('button', { name: 'Add 2-column section' }).click();
    const columns = canvas(page).locator('.cvb-container .cvb-container');
    await expect(columns).toHaveCount(2);
    await drag(page, (await tile(page, 'Heading')), columns.first());
    await expect(columns.first().locator('.cvb-heading')).toHaveText('Add Your Heading Text Here');

    // Drop a button below the heading inside the same column.
    await drag(page, (await tile(page, 'Button')), columns.first().locator('.cvb-heading'), 'bottom');
    const firstColumnWidgets = columns.first().locator('.cvb-container__inner > .cvb-el');
    await expect(firstColumnWidgets).toHaveCount(2);
    await expect(firstColumnWidgets.nth(1)).toHaveClass(/cvb-button/);

    // Inline editing: select, then type into the heading itself.
    const heading = node(page, 'heading');
    await heading.click();
    await expect(heading).toHaveAttribute('contenteditable', 'plaintext-only');
    await heading.press('ControlOrMeta+a');
    await heading.pressSequentially('Hello builder');
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue('Hello builder');

    // Panel edit shows on the canvas immediately (no reload).
    await page.getByRole('tab', { name: 'Style' }).click();
    await page.getByLabel('Text Color', { exact: true }).fill('#ff0000');
    await expect(heading).toHaveCSS('color', 'rgb(255, 0, 0)');
    await waitSaved(page);
  });

  test('FR-06: responsive values differ per device', async ({ page }) => {
    await createPage(page, 'Responsive');
    await (await tile(page, 'Heading')).click();
    const heading = node(page, 'heading');
    await page.getByRole('tab', { name: 'Style' }).click();
    await page.getByRole('button', { name: /^Typography/ }).click();
    await page.getByLabel('Size', { exact: true }).fill('60');
    await expect(heading).toHaveCSS('font-size', '60px');

    await page.getByRole('button', { name: 'Mobile (360px)' }).click();
    await expect(page.locator('.ed-frame')).toHaveCSS('width', '360px');
    await expect(heading).toHaveCSS('font-size', '60px'); // inherited from desktop
    await page.getByLabel('Size', { exact: true }).fill('24');
    await expect(heading).toHaveCSS('font-size', '24px');

    await page.getByRole('button', { name: 'Desktop' }).click();
    await expect(heading).toHaveCSS('font-size', '60px');
  });

  test('selection: toolbar follows the selection and settings switch elements', async ({ page }) => {
    await createPage(page, 'Selection');
    await (await tile(page, 'Heading')).click();
    await (await tile(page, 'Button')).click();
    await node(page, 'heading').click();
    await expect(page.locator('.ed-toolbar')).toContainText('Heading');
    await expect(page.getByRole('heading', { name: 'Edit Heading' })).toBeVisible();
    await node(page, 'button').click();
    await expect(page.locator('.ed-toolbar')).toContainText('Button');
    await expect(page.getByRole('heading', { name: 'Edit Button' })).toBeVisible();
    await page.locator('.ed-toolbar').getByRole('button', { name: 'Select parent' }).click();
    await expect(page.getByRole('heading', { name: 'Edit Container' })).toBeVisible();
    await page.locator('.ed-toolbar').getByRole('button', { name: 'Delete' }).click();
    await expect(canvas(page).locator('.cvb-el')).toHaveCount(0);
  });

  test('FR-07: undo and redo', async ({ page }) => {
    await createPage(page, 'History');
    await (await tile(page, 'Spacer')).click();
    await (await tile(page, 'Divider')).click();
    await expect(canvas(page).locator('.cvb-divider')).toHaveCount(1);
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(canvas(page).locator('.cvb-divider')).toHaveCount(0);
    await expect(canvas(page).locator('.cvb-spacer')).toHaveCount(1);
    await page.getByRole('button', { name: 'Redo' }).click();
    await expect(canvas(page).locator('.cvb-divider')).toHaveCount(1);
    await page.locator('body').press('ControlOrMeta+z');
    await expect(canvas(page).locator('.cvb-divider')).toHaveCount(0);
  });

  test('FR-08/09/10: autosave persists, publish renders without the editor', async ({ page, request }) => {
    const id = await createPage(page, 'Launch day');
    await (await tile(page, 'Heading')).click();
    const heading = node(page, 'heading');
    await heading.press('ControlOrMeta+a');
    await heading.pressSequentially('We are live <3');
    await waitSaved(page);

    await page.reload();
    await expect(node(page, 'heading')).toHaveText('We are live <3');

    await page.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByText('Published. The live page is updated.')).toBeVisible();

    const scripts: string[] = [];
    const visitor = await page.context().browser()!.newPage();
    visitor.on('request', (r) => scripts.push(r.url()));
    const response = await visitor.goto('/launch-day');
    expect(response?.status()).toBe(200);
    expect(response?.headers()['content-security-policy']).toContain("default-src 'none'");
    await expect(visitor.locator('h2.cvb-heading')).toHaveText('We are live <3');
    expect(scripts.filter((u) => u.includes('/editor/') || u.endsWith('.js'))).toEqual([]);
    await visitor.close();

    // A draft edit does not change the live page until the next publish.
    await node(page, 'heading').click();
    await node(page, 'heading').press('ControlOrMeta+a');
    await node(page, 'heading').pressSequentially('Draft only');
    await waitSaved(page);
    const live = await request.get('/launch-day');
    expect(await live.text()).toContain('We are live &lt;3');
    expect(id).toBeGreaterThan(0);
  });

  test('FR-12: failed saves keep changes, retry, and survive a reload', async ({ page }) => {
    await createPage(page, 'Unreliable network');
    await page.route('**/api/pages/*/draft', (route) => route.fulfill({ status: 500, body: '{"error":"boom"}', contentType: 'application/json' }));
    await (await tile(page, 'Heading')).click();
    await expect(page.getByRole('status').filter({ hasText: 'Save failed' })).toBeVisible({ timeout: 10_000 });
    await expect(node(page, 'heading')).toBeVisible();

    // Reload while the server still refuses: the local backup is offered back.
    page.on('dialog', (d) => void d.accept());
    await page.reload();
    await expect(page.getByText(/Unsaved changes from .* were found in this browser/)).toBeVisible();
    await page.unroute('**/api/pages/*/draft');
    await page.getByRole('button', { name: 'Restore' }).click();
    await expect(node(page, 'heading')).toBeVisible();
    await waitSaved(page);
    await page.reload();
    await expect(node(page, 'heading')).toBeVisible();
    await expect(page.getByText(/were found in this browser/)).toHaveCount(0);
  });

  test('FR-12: concurrent edits surface a conflict instead of overwriting', async ({ page, browser }) => {
    const id = await createPage(page, 'Two tabs');
    await (await tile(page, 'Spacer')).click();
    await waitSaved(page);

    const other = await browser.newPage({ storageState: await page.context().storageState() });
    await other.goto(`/editor/edit/?page=${id}`);
    await (await tile(other, 'Divider')).click();
    await waitSaved(other);
    await other.close();

    await (await tile(page, 'Heading')).click();
    await expect(page.getByText('This page was changed elsewhere')).toBeVisible({ timeout: 10_000 });
    await page.getByRole('button', { name: 'Keep mine' }).click();
    await waitSaved(page);
  });
});

test('FR-11: authors cannot edit other users\' pages', async ({ page, browser }) => {
  await login(page, ADMIN);
  const id = await createPage(page, 'Admin only');

  const authorPage = await browser.newPage();
  await login(authorPage, AUTHOR);
  const response = await authorPage.request.get(`/api/pages/${id}`);
  expect(response.status()).toBe(403);
  await authorPage.goto('/editor/');
  await expect(authorPage.getByRole('link', { name: 'Admin only' })).toHaveCount(0);
  await authorPage.close();
});
