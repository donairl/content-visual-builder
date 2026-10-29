import { expect, type Page } from '@playwright/test';

export interface Credentials {
  email: string;
  password: string;
}

/** Seeded by e2e/serve-backend.mjs. */
export const ADMIN: Credentials = { email: 'admin@e2e.test', password: 'e2e-admin-password' };
export const AUTHOR: Credentials = { email: 'author@e2e.test', password: 'e2e-author-password' };

export async function login(page: Page, who: Credentials) {
  await page.goto('/login?return=/editor/');
  await page.getByLabel('Email').fill(who.email);
  await page.getByLabel('Password').fill(who.password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL('**/editor/');
  await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible();
}
