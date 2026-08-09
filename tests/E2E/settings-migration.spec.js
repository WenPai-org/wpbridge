const { test, expect } = require('@playwright/test');

test('legacy vendor id migration is visible in settings UI', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.WPBRIDGE_E2E_USER || 'admin');
  await page.locator('#user_pass').fill(process.env.WPBRIDGE_E2E_PASSWORD || 'pass1234');
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/wp-admin/);

  await page.goto('/wp-admin/admin.php?page=wpbridge', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.wpbridge-wrap')).toBeVisible();
  await page.locator('[data-tab="projects"]').click();

  const migrated = page.locator('.wpbridge-source-list-item[data-source-id="vendor_weixiaoduo-mall"]');
  await expect(migrated).toBeVisible();
  await expect(migrated).toContainText('Round 2 Legacy Vendor');
  await expect(page.locator('.wpbridge-source-list-item[data-source-id="vendor_weixiaoduo-store"]')).toHaveCount(0);
});
