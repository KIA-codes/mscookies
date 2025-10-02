import re

# Read the file
with open('c:/xampp/htdocs/roben/generate_reports.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Solution: Create ONE fixed download button outside the slides, and update its href dynamically
# We'll place it in the .report-content div, after the navigation buttons

# 1. Remove all existing slide-download-btn from inside slides
content = re.sub(
    r'\s*<a href="export_report_[^"]*\.php"[^>]*class="slide-download-btn"[^>]*>.*?</a>',
    '',
    content,
    flags=re.DOTALL
)

# 2. Add a single fixed download button in the report-content area (after the next-btn)
pattern = r'(<button class="nav-btn next-btn" onclick="navigateReport\(1\)">❯</button>)'
replacement = r'''\1
      
      <!-- Fixed Download Button -->
      <a href="export_report_all.php" target="_blank" id="fixedDownloadBtn" class="slide-download-btn" title="Download Report">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
        </svg>
        Download
      </a>'''

content = re.sub(pattern, replacement, content)

# 3. Update the JavaScript to target the single button instead of multiple buttons
old_js = r"  const downloadButtons = document\.querySelectorAll\('\.slide-download-btn'\);"
new_js = r"  const downloadBtn = document.getElementById('fixedDownloadBtn');"

content = content.replace(old_js, new_js)

old_js2 = r"  // Update all download button hrefs\s*downloadButtons\.forEach\(btn => \{\s*btn\.href = exportFile;\s*\}\);"
new_js2 = r"  // Update the fixed download button href\n  if (downloadBtn) {\n    downloadBtn.href = exportFile;\n  }"

content = re.sub(old_js2, new_js2, content)

# Write back
with open('c:/xampp/htdocs/roben/generate_reports.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("✓ Successfully updated generate_reports.php")
print("✓ Removed all individual slide download buttons")
print("✓ Added single fixed download button in bottom-right corner")
print("✓ Updated JavaScript to control the single button")
