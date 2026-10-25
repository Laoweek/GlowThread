// Scrolls to the bottom of the page and waits 3 seconds, so anything loaded on scroll can render.
export default async function (page, helpers) {
  const scrollY = await page.evaluate(() => {
    window.scrollTo(0, document.documentElement.scrollHeight);
    return window.scrollY;
  });
  await helpers.sleep(3000);
  return { scrollY };
}
