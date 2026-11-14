// QR codes for the app download page (used by build-cpanel.sh).
//   node scripts/app-qr.cjs <domain> <apps folder> <png folder>
// Writes qr-customer.svg / qr-agent.svg into the apps folder (used by index.html and print.html) and
// large PNGs into the png folder, to share on WhatsApp or send to a printer.
const path = require('path')
const QRCode = require(path.join(__dirname, '../web/node_modules/qrcode'))

const [domain, appsDir, pngDir] = process.argv.slice(2)
const opts = { errorCorrectionLevel: 'M', margin: 2, color: { dark: '#1f1e1dff', light: '#ffffffff' } }

;(async () => {
  for (const app of ['customer', 'agent']) {
    const url = `https://${domain}/apps/?app=${app}`
    await QRCode.toFile(path.join(appsDir, `qr-${app}.svg`), url, { ...opts, type: 'svg' })
    await QRCode.toFile(path.join(pngDir, `KBSAVE-${app === 'customer' ? 'Customer' : 'Agent'}-app-QR.png`), url, { ...opts, type: 'png', width: 1200 })
    console.log(`  ${app}: ${url}`)
  }
})().catch((e) => { console.error(e); process.exit(1) })
