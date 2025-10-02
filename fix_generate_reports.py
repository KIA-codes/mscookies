import re

# Read the file
with open('c:/xampp/htdocs/roben/generate_reports.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove the download button from header (keep only close button)
content = re.sub(
    r'      <a href="export_report\.php"[^>]*>.*?</a>\s*\n',
    '',
    content,
    flags=re.DOTALL
)

# 2. Add download button to Slide 1 (Summary) - after </div> before </div> (end of slide)
slide1_pattern = r'(            <?php endforeach; \?>\s*</div>\s*)(</div>\s*<!-- Slide 2)'
slide1_button = r'''\1          <a href="export_report_all.php" target="_blank" class="slide-download-btn" title="Download All Time Report">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
            </svg>
            Download
          </a>
        \2'''
content = re.sub(slide1_pattern, slide1_button, content)

# 3. Add download button to Slide 2 (All Sales) - after </div> before </div> (end of slide)
slide2_pattern = r'(            </table>\s*</div>\s*)(</div>\s*<!-- Slide 3)'
slide2_button = r'''\1          <a href="export_report_all.php" target="_blank" class="slide-download-btn" title="Download All Time Report">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
            </svg>
            Download
          </a>
        \2'''
content = re.sub(slide2_pattern, slide2_button, content)

# Write back
with open('c:/xampp/htdocs/roben/generate_reports.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("✓ Successfully updated generate_reports.php")
print("✓ Removed download button from header")
print("✓ Added download buttons to Slide 1 and Slide 2")
