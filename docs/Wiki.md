** [🏠 Home](../README.md) • **📚 User Guide** • [📊 Project Overview](PROJECT_OVERVIEW.md) • [🚀 Developer Guide](DEVELOPER_GUIDE.md) • [🔄 Changelog](https://github.com/Frank-Loong/Notion-to-WordPress/commits)

**🌐 Language:** **English** • [中文](Wiki.zh_CN.md)

---

# 📚 Notion to WordPress - User Guide

> **The most advanced and reliable Notion-to-WordPress integration solution - Complete tutorial from zero to expert**

Welcome to the official documentation hub for **Notion to WordPress**! This guide will take you from zero to mastering this powerful integration tool, achieving seamless synchronization between Notion and WordPress.

---

## 📋 Complete Table of Contents

### 🚀 Part I: Quick Start (Essential for Beginners)
- [🎯 Environment Requirements & Preparation](#-environment-requirements--preparation)
- [📦 Complete Installation Guide](#-complete-installation-guide)
- [⚙️ Basic Configuration Setup](#️-basic-configuration-setup)
- [🎉 First Sync Verification](#-first-sync-verification)

### 🔧 Part II: Advanced Usage (Advanced Features)
- [🎛️ Advanced Configuration Options](#️-advanced-configuration-options)
- [⚡ Three Sync Modes Explained](#-three-sync-modes-explained)
- [🎨 Content Processing & Conversion](#-content-processing--conversion)
- [🔒 Security & Performance Optimization](#-security--performance-optimization)

### 🆘 Part III: Problem Solving (Support & Help)
- [❓ Frequently Asked Questions](#-frequently-asked-questions)
- [🐞 Systematic Troubleshooting](#-systematic-troubleshooting)
- [📝 Best Practices](#-best-practices)

### 📚 Part IV: Resources & Extensions
- [🔗 Official Resource Links](#-official-resource-links)
- [📖 Advanced Learning Materials](#-advanced-learning-materials)
- [🤝 Community & Support](#-community--support)

---

# 🚀 Part I: Quick Start (Essential for Beginners)

## 🎯 Environment Requirements & Preparation

Before using the Notion to WordPress plugin, please ensure your environment meets the following requirements. We provide detailed checklists to help you quickly verify and prepare your environment.

### 📋 System Requirements Checklist

#### ✅ WordPress Environment Requirements
- **WordPress Version:** 5.0 or higher
- **User Permissions:** Administrator privileges (ability to install and activate plugins)
- **Theme Compatibility:** Themes that support standard WordPress hooks
- **Required Plugins:** No special requirements, compatible with most plugins

> **💡 How to Check:** Go to WordPress Admin → Dashboard → At a Glance to view WordPress version information

#### ✅ Server Environment Requirements
- **PHP Version:** 7.4 or higher (8.0+ recommended)
- **Required Extensions:** `curl`, `mbstring`, `json`, `openssl`
- **Memory Limit:** Minimum 128MB (256MB+ recommended)
- **Execution Time:** Minimum 60 seconds (300 seconds+ recommended)
- **Upload Limit:** Minimum 10MB (50MB+ recommended)

> **💡 How to Check:** Use plugins like "Site Health Status" or go to WordPress Admin → Tools → Site Health

#### ✅ Notion Account Requirements
- **Account Type:** Personal or team accounts both supported
- **Permission Requirements:** Ability to create integrations and edit target databases
- **Database Access:** Full editing permissions for sync databases

### 🗄️ Notion Database Preparation

To ensure optimal sync results, you need to prepare a Notion database that meets requirements. We provide multiple template options:

#### 🎯 Official Recommended Templates

<div align="center">

| Template Type | Features | Link | Recommended Use Case |
|---------------|----------|------|----------------------|
| 📝 **Chinese Blog Template** | Complete field configuration, Chinese optimized | [Copy Template](https://frankloong.notion.site/22a7544376be808abbadc3d09fde153d?v=22a7544376be813dba3c000c778c0099&source=copy_link) | Chinese blogs, personal websites |
| 📚 **English Blog Template** | English environment optimized, internationalization support | [Copy Template](https://frankloong.notion.site/22a7544376be80799930fc75da738a5b?v=22a7544376be819793c8000cc2623ae3&source=copy_link) | English blogs, international websites |

</div>

#### 🔗 NotionNext Ecosystem Compatibility

**🎉 Major Feature: Dual-Platform Publishing Capability**

This plugin is fully compatible with [NotionNext](https://github.com/tangly1024/NotionNext), enabling true "write once, publish everywhere":

<div align="center">

| Platform Combination | Template Link | Use Case |
|----------------------|---------------|----------|
| 🇨🇳 **NotionNext + WordPress Chinese** | [NotionNext Chinese Template](https://tanghh.notion.site/02ab3b8678004aa69e9e415905ef32a5?v=b7eb215720224ca5827bfaa5ef82cf2d) | Chinese tech blogs, knowledge sharing |
| 🇺🇸 **NotionNext + WordPress English** | [NotionNext English Template](https://www.notion.so/tanghh/7c1d570661754c8fbc568e00a01fd70e?v=8c801924de3840b3814aea6f13c8484f&pvs=4) | International content publishing |

</div>

> **🚀 Dual-Platform Publishing Advantages:**
> - ✅ **Unified Content Management:** Manage all content in Notion
> - ✅ **Automatic Sync:** Content automatically syncs to both NotionNext and WordPress
> - ✅ **SEO Optimization:** Both platforms get search engine indexing
> - ✅ **Audience Coverage:** Reach users across different platforms

### 🔍 Environment Check Tools

Before proceeding, we recommend verifying your environment using the following methods:

```bash
# Check PHP version and extensions
php -v
php -m | grep -E "(curl|mbstring|json|openssl)"

# Check WordPress version (run in WordPress root directory)
wp core version
```

**✅ Preparation Completion Checklist:**
- [ ] WordPress version ≥ 5.0
- [ ] PHP version ≥ 7.4 with required extensions
- [ ] WordPress administrator privileges
- [ ] Notion account and database editing permissions
- [ ] Selected and copied appropriate database template

---

## 📦 Complete Installation Guide

This chapter will guide you through the complete process from Notion configuration to WordPress plugin installation. The entire process takes approximately 10-15 minutes.

### 🔧 Step 1: Configure Notion Integration

#### 🔑 Create Notion Integration and Get API Key

**1. Access Notion Integration Management Page**
- Open [Notion Integrations Page](https://www.notion.so/profile/integrations/)
- Log in with your Notion account

<div align="center">
  <img src="../docs/images/notion-integrations.png" alt="Notion Integrations Page" width="800">
  <p><em>Notion Integration Management Interface</em></p>
</div>

**2. Create New Integration**
- Click "**+ New integration**" button
- Fill in integration information:
  - **Name:** `WordPress Sync` or `WordPress Sync`, can also be customized
  - **Logo:** Optional, upload custom icon
  - **Associated workspace:** Select workspace containing target database

**3. Configure Integration Permissions**
- ✅ **Read content** - Required, for reading page content
- ✅ **Read user information** - Recommended, for getting author information
- ❌ **Update content** - Not needed, plugin only reads without modifying
- ❌ **Insert content** - Not needed

**4. Save and Get Key**
- Click "**Submit**" to save integration
- On integration details page, copy "**Internal Integration Token**"
- ⚠️ **Important:** Please save this key securely, it will be needed for subsequent configuration

<div align="center">
  <img src="../docs/images/notion-internal-integration-key.png" alt="Copy Internal Integration Token" width="800">
  <p><em>Copy and save your Internal Integration Token</em></p>
</div>

#### 📊 Get Database ID and Configure Permissions

**1. Find Target Database**
- Open the Notion database page you want to sync
- Ensure database contains necessary properties (title, status, etc.)

**2. Invite Integration to Database**
- Click the "**three dots**" button in the upper right corner of the database page.
- Click "**Integration**" in the pop-up window.
- Search for and select the integration you just created (such as "WordPress Sync").
- Click "**Add Connection**" to complete the addition.

<div align="center">
  <img src="../docs/images/notion-database-id.png" alt="Add Integration and Copy Database ID" width="800">
  <p><em>Link your integration to database and copy Database ID</em></p>
</div>

**3. Get Database ID**
- In the browser address bar on database page, find the URL
- Database ID is the 32-character string in URL: `https://www.notion.so/DATABASE_ID?v=...`
- Copy this 32-character string (excluding `?v=` and everything after)

> **💡 Quick Tips:**
> - Method 1: On database page, click "**⋯**" in top right → "**Copy link**", extract ID from link
> - Method 2: Directly copy ID portion from browser address bar URL

**✅ Step 1 Completion Check:**
- [ ] Created Notion integration and obtained API key
- [ ] Invited integration to target database
- [ ] Obtained 32-character database ID
- [ ] API key and database ID safely saved

### 🔌 Step 2: Install WordPress Plugin

#### 📦 Method 1: WordPress Admin Installation (Recommended)

**1. Download Plugin File**
- Visit [GitHub Releases Page](https://github.com/Frank-Loong/Notion-to-WordPress/releases)
- Download the latest version of `notion-to-wordpress.zip` file
- Ensure you download the `.zip` format plugin package

**2. Upload and Install Plugin**
- Log into WordPress admin dashboard
- Go to `Plugins` → `Add New` → `Upload Plugin`

<div align="center">
  <img src="../docs/images/install.png" alt="WordPress Plugin Installation" width="800">
  <p><em>WordPress Plugin Upload Interface</em></p>
</div>

- Click "**Choose File**", select the `.zip` file you just downloaded
- Click "**Install Now**" to start installation
- After installation completes, click "**Activate Plugin**"

**3. Verify Installation**
- Look for "**Notion to WordPress**" in the WordPress admin left sidebar menu
- If you see this menu item, the plugin installation was successful

#### 🔧 Method 2: FTP Upload (Advanced Users)

**Use Case:** Server restricts file uploads or batch deployment needed

**Steps:**
1. **Extract Plugin Files**
   ```bash
   unzip notion-to-wordpress.zip
   ```

2. **Upload to Server**
   - Use FTP client to connect to server
   - Upload extracted `notion-to-wordpress/` folder to `/wp-content/plugins/` directory

3. **Activate Plugin**
   - Go to WordPress admin dashboard → `Plugins`
   - Find "Notion-to-WordPress" and click "**Activate**"

#### ⚡ Method 3: WP-CLI Command Line (Developers)

**Use Case:** Server management, batch deployment, automation scripts

```bash
# Download and install latest version
wp plugin install https://github.com/Frank-Loong/Notion-to-WordPress/releases/latest/download/notion-to-wordpress.zip

# Activate plugin
wp plugin activate notion-to-wordpress

# Verify installation (optional)
wp plugin list | grep notion-to-wordpress
```

**✅ Step 2 Completion Check:**
- [ ] Plugin file successfully downloaded
- [ ] Plugin installed to WordPress
- [ ] Plugin successfully activated
- [ ] Can see "Notion to WordPress" option in admin menu

---

## ⚙️ Basic Configuration Setup

### 🎛️ Step 3: WordPress Plugin Configuration

#### 🔧 Access Plugin Settings Page

**1. Enter Settings Interface**
- In WordPress admin left sidebar, click "**Notion to WordPress**"
- Or go to `Settings` → `Notion to WordPress`

**2. Interface Overview**
Plugin settings page includes the following main sections:
- **Basic Settings:** API key and database configuration
- **Field Mapping:** Correspondence between Notion properties and WordPress fields
- **Sync Options:** Sync frequency and behavior settings
- **Advanced Options:** Debug, security, and performance settings

#### 🔑 Configure API Connection

**1. Fill Basic Information**
- **Notion API Token:** Paste the Internal Integration Token obtained in step 1
- **Database ID:** Paste the 32-character database ID obtained in step 1

<div align="center">
  <img src="../docs/images/wordpress-config.png" alt="WordPress Configuration" width="600">
  <p><em>Configure Notion integration information in WordPress</em></p>
</div>

**2. Test Connection**
- After filling in, click "**Test Connection**" button
- If it shows "✅ Connection Successful", configuration is correct
- If it shows an error, please check if API key and database ID are correct

#### 📋 Basic Field Mapping (Quick Start)

**For new users, we recommend keeping default field mapping settings:**

| WordPress Field | Notion Property Name | Description |
|----------------|---------------------|-------------|
| Post Title | `Title` or `标题` | Main article title |
| Publish Status | `Status` or `状态` | Controls whether article is published |
| Post Type | `Type` or `类型` | Article or page type |
| Publish Date | `Date` or `日期` | Article publication time |

> **💡 Tip:** If you're using our provided templates, default field mapping is already fully matched and requires no modification.

#### ⏰ Sync Schedule Settings

**Choose appropriate sync frequency:**
- **Manual Sync:** Complete manual control, suitable for testing and on-demand updates
- **Daily:** Suitable for daily blog updates
- **Twice Daily:** Suitable for frequently updated content websites
- **Weekly:** Suitable for low-frequency updated content

> **🚀 First-time Use Recommendation:** Choose "**Manual Sync**", then set up automatic sync after familiarizing with the process.

**4. Save Configuration**
- After checking all settings are correct, click "**Save Settings**" button
- System will display "Settings Saved" confirmation message

**✅ Step 3 Completion Check:**
- [ ] Successfully filled in API key and database ID
- [ ] Connection test shows success
- [ ] Field mapping configuration completed (can use default settings)
- [ ] Sync schedule selected
- [ ] Settings successfully saved

---

## 🎉 First Sync Verification

### 🚀 Step 4: Execute First Sync

#### 📊 Start Sync

**1. Initiate Manual Sync**
- On plugin settings page top, click "**Manual Sync**" button
- System will display sync progress bar and real-time status

**2. Observe Sync Process**
- **Progress Display:** Real-time sync progress percentage
- **Status Information:** Shows currently processing content
- **Statistics:** Shows number of synced articles

**3. Wait for Sync Completion**
- Sync time depends on amount of content in database
- Usually 10-50 articles take 1-3 minutes
- Do not close page during sync process

#### ✅ Verify Sync Results

**1. Check Article List**
- Go to WordPress admin → `Posts` → `All Posts`
- Check if articles from Notion appear
- Verify article titles and status are correct

**2. View Article Content**
- Click any synced article to edit
- Check if content format is correctly preserved
- Verify images, links and other elements are normal

**3. Frontend Display Verification**
- Visit website frontend to view article display
- Check if math formulas, charts and other special content render normally

#### 📋 Common First Sync Issues

| Symptom | Possible Cause | Solution |
|---------|----------------|----------|
| Shows `Invalid token` | API Token copied incorrectly or expired | Regenerate API key and update settings |
| Shows `Database not found` | Integration not invited or database ID incorrect | Confirm integration invited to database, check ID is correct |
| Some articles not synced | Article status doesn't meet sync conditions | Check Notion article status field settings |
| Images display abnormally | Image download failed or permission issues | Check `wp-content/uploads` directory permissions |
| Formulas/charts not rendering | Theme blocked related resource loading | Check theme compatibility, contact theme developer if necessary |

**✅ Step 4 Completion Check:**
- [ ] Manual sync executed successfully
- [ ] Notion content appears in WordPress article list
- [ ] Article content format correct, images display normally
- [ ] Frontend page display meets expectations
- [ ] No error messages or abnormal conditions

---

### 🎊 Congratulations! Basic Setup Complete

If you've completed the above four steps, congratulations! You've successfully established a basic sync connection between Notion and WordPress.

**🎯 Next Steps:**
- 📖 Continue reading [Part II: Advanced Usage](#-part-ii-advanced-usage-advanced-features) to learn about advanced features
- ⚡ Set up [Webhook Auto-Sync](#-webhook-auto-sync) for real-time updates
- 🎨 Customize [Field Mapping Configuration](#️-field-mapping-configuration) for special needs
- 🔒 Optimize [Security & Performance Settings](#-security--performance-optimization) to enhance user experience

---

# 🔧 Part II: Advanced Usage (Advanced Features)

## 🎛️ Advanced Configuration Options

### 📋 Detailed Field Mapping Configuration

Field mapping is the core functionality of the plugin, determining how Notion database properties correspond to WordPress fields. Correct field mapping configuration is key to ensuring sync effectiveness.

#### 🔑 Core Field Mapping Details

| WordPress Field | Notion Property Names | Data Type | Description & Configuration Points |
|----------------|----------------------|-----------|-----------------------------------|
| **Post Title** | `Title`, `标题` | Title | Required field, serves as WordPress post title |
| **Publish Status** | `Status`, `状态` | Select | Controls post publish status, supports multiple status values |
| **Post Type** | `Type`, `类型` | Select | Specifies WordPress content type (post, page, etc.) |
| **Publish Date** | `Date`, `日期` | Date | Sets post publish time, supports automatic and manual setting |
| **Excerpt** | `Summary`, `摘要`, `Excerpt` | Text/Rich Text | Post excerpt for SEO and list display |
| **Featured Image** | `Featured Image`, `特色图片` | File/URL | Post featured image, supports Notion files and external links |
| **Categories** | `Categories`, `分类`, `Category` | Multi-select | Post categories, supports multiple categories |
| **Tags** | `Tags`, `标签`, `Tag` | Multi-select | Post tags, supports multiple tags |
| **Password Protection** | `Password`, `密码` | Text | Sets post access password, automatically enables password protection when non-empty |

#### 📊 Status Field Configuration Details

Status field is the key field controlling post publishing, supporting the following status values:

**Publish Status Mapping:**
```
✅ Published Posts:
- Published, 已发布, publish, public, 公开, live, 上线

🔒 Private Posts:
- Private, 私密, private_post

📝 Draft Status:
- Draft, 草稿, unpublished, 未发布

🔐 Password Protected:
- Any status + non-empty password field = password protected post
```

#### 🎨 Custom Field Mapping

Besides core fields, you can map any Notion property to WordPress custom fields:

**Supported Notion Data Types:**
- **Text Types:** Text, Rich Text, URL, Email, Phone
- **Number Types:** Number, Currency
- **Select Types:** Select, Multi-select
- **Date Types:** Date, Created Time, Last Edited Time
- **Relation Types:** Person, Relation, Formula
- **Media Types:** Files, Images

**Configuration Examples:**
```
Notion Property "Author" (Person) → WordPress Custom Field "post_author_name"
Notion Property "Reading Time" (Number) → WordPress Custom Field "reading_time"
Notion Property "SEO Keywords" (Multi-select) → WordPress Custom Field "seo_keywords"
```

#### ⚙️ Advanced Mapping Options

**1. Conditional Mapping**
- Map to different WordPress fields based on different Notion property values
- Support regex matching and conditional logic

**2. Data Transformation**
- Automatically convert data formats (e.g., date format, number format)
- Support custom transformation rules

**3. Default Value Settings**
- Set default values for empty fields
- Support dynamic default values (e.g., current time, current user, etc.)

### 🔄 Sync Options Configuration

#### 📅 Sync Frequency Settings

Choose appropriate sync schedule based on your content update frequency:

| Sync Frequency | Use Case | Resource Usage | Recommendation |
|---------------|----------|----------------|----------------|
| **Manual Sync** | Test environment, on-demand updates | Lowest | ⭐⭐⭐⭐⭐ |
| **Twice Daily** | Active blogs, news websites | Medium | ⭐⭐⭐⭐ |
| **Daily** | Personal blogs, corporate websites | Lower | ⭐⭐⭐⭐⭐ |
| **Weekly** | Documentation sites, knowledge bases | Lowest | ⭐⭐⭐ |
| **Bi-weekly** | Archive content, static websites | Lowest | ⭐⭐ |

#### 🎯 Smart Sync Features

**1. Incremental Sync**
- **Function:** Only sync content that has changed since last sync
- **Advantage:** 80%+ performance improvement, reduced server load
- **Principle:** Compare Notion's `last_edited_time` with local sync records
- **Configuration:** `Settings` → `Sync Options` → `Enable Incremental Sync`

**2. Smart Delete Detection**
- **Function:** Automatically detect and handle content deleted in Notion
- **Safety Measures:** Detailed logging, configurable delete behavior
- **Processing Options:** Delete, move to trash, mark as draft
- **Configuration:** `Settings` → `Sync Options` → `Enable Delete Detection`

**3. Content-Aware Processing**
- **Function:** Distinguish content changes from property updates, using different processing strategies
- **Advantage:** Avoid unnecessary full syncs, improve efficiency
- **Auto Fallback:** Automatically fallback to full sync when detection fails

### 🔧 Debug & Log Configuration

#### 📊 Log Level Settings

| Level | Recorded Content | Use Case | Performance Impact |
|-------|------------------|----------|-------------------|
| **None** | No logging | Production (not recommended) | None |
| **Error** | Only errors | Production environment | Very Low |
| **Info** | Important info and errors | Test environment | Low |
| **Debug** | Detailed debug info | Development debugging | Medium |

#### 📁 Log Management

**Log Storage Location:** `wp-content/uploads/notion-to-wordpress-logs/`

**Log File Structure:**
```
notion-to-wordpress-logs/
├── sync-2024-12-10.log          # Sync logs
├── webhook-2024-12-10.log       # Webhook logs
├── error-2024-12-10.log         # Error logs
└── debug-2024-12-10.log         # Debug logs
```

**Log Viewing and Management:**
- **Online Viewing:** Plugin settings page → `Log Management` → `View Logs`
- **Download Logs:** Support downloading log files for specific dates
- **Clean Logs:** One-click cleanup of expired log files
- **Log Rotation:** Automatically delete logs older than 30 days

## ⚡ Three Sync Modes Explained

### 🎯 Sync Mode Selection Guide

Choose the most suitable sync mode based on your use case and requirements:

| Sync Mode | Trigger Method | Real-time | Use Case | Technical Requirements | Recommendation |
|-----------|----------------|-----------|----------|----------------------|----------------|
| **🖱️ Manual Sync** | Manual button click | Instant | Testing, on-demand updates, full control | None | ⭐⭐⭐⭐⭐ |
| **⏰ Scheduled Sync** | System cron jobs | Delayed | Regular automation, unattended | WordPress Cron | ⭐⭐⭐⭐ |
| **⚡ Webhook Sync** | Notion event triggered | Real-time | Real-time publishing, team collaboration | Public access, SSL | ⭐⭐⭐⭐⭐ |

### 🖱️ Manual Sync Mode

**Features:** Complete manual control, suitable for testing and precise sync timing control

**Usage:**
1. Go to plugin settings page
2. Click "**Manual Sync**" button at top of page
3. Observe sync progress and results

**Use Cases:**
- ✅ Initial setup and testing
- ✅ Final check before content publishing
- ✅ Need precise control over sync timing
- ✅ Limited server resource environments

**Advantages:**
- 🎯 Fully controllable, execute on demand
- 🚀 Instant sync, no delay
- 💡 Easy debugging and troubleshooting
- 🔒 Highest security, no automation risks

### ⏰ Scheduled Sync Mode

**Features:** Automated sync based on WordPress Cron system

**Configuration:**
1. Select sync frequency in plugin settings
2. Save settings to automatically enable scheduled tasks
3. Can view Cron status in `Tools` → `Site Health`

**Sync Frequency Options:**
```
📅 Twice Daily    → Suitable for active blogs, news websites
📅 Daily          → Suitable for personal blogs, corporate websites
📅 Weekly         → Suitable for documentation sites, knowledge bases
📅 Bi-weekly      → Suitable for archive content, static websites
📅 Monthly        → Suitable for very low-frequency update content
```

**Technical Requirements:**
- WordPress Cron system running normally
- Server supports background task execution
- Sufficient PHP execution time and memory

**Monitoring and Management:**
- View last sync time and results
- Set email notifications for sync failures
- Support manual triggering of scheduled tasks

### ⚡ Webhook Real-time Sync Mode

**Features:** Real-time sync based on Notion Webhooks, content changes sync instantly

**How It Works:**
1. Notion detects database changes
2. Sends Webhook request to WordPress
3. Plugin receives request and triggers sync
4. Returns processing result to Notion

**Event Type Processing:**
- **`page.content_updated`** → Force immediate sync, bypass incremental detection
- **`page.properties_updated`** → Smart incremental sync
- **`page.deleted`** → Immediately delete corresponding WordPress content
- **`page.undeleted`** → Full sync to restore content
- **`database.updated`** → Comprehensive sync + delete detection

**Configuration Requirements:**
- Website must be publicly accessible
- SSL certificate recommended
- Server supports receiving POST requests

**Performance Optimization Features:**
- 🚀 Asynchronous response, immediate Webhook confirmation
- 🔄 Background processing, avoid timeouts
- 🛡️ Automatic retry mechanism
- ⚡ Rate limiting protection

#### 🔧 Webhook Configuration Detailed Steps

**1. Enable Webhook Support**
```
WordPress Admin → Notion to WordPress → Other Settings → Enable Webhook Support
```

**2. Get Webhook URL**
After enabling, a URL similar to the following format will be displayed:
```
https://yoursite.com/wp-json/notion-to-wordpress/v1/webhook
```

**3. Configure Webhook in Notion**
- Visit [Notion Integrations](https://www.notion.so/my-integrations)
- Select your integration → Settings → Webhooks
- Add a new Webhook endpoint
- Paste the above URL
- Select the event types to listen to

**4. Verify the Configuration**
- Send a verification request in Notion
- Click the Refresh Verification Token button on the WordPress plugin settings page
- Obtain the verification token and copy it to the Notion integration settings to complete the verification

**5. Test the Webhook**
- Modify the database content in Notion
- Check if WordPress synchronizes automatically
- View the plugin logs to confirm the Webhook call

---

## 🎨 Content Processing & Conversion

### 📝 Supported Notion Block Types

The plugin supports almost all common Notion block types and can accurately convert them to corresponding WordPress content:

#### 📄 Text Content Blocks
| Notion Block Type | WordPress Conversion | Preserved Features | Notes |
|------------------|---------------------|-------------------|-------|
| **Paragraph** | `<p>` tags | Rich text formatting, links, inline code | Full support |
| **Headings 1-6** | `<h1>` - `<h6>` | Heading levels, anchor links | Auto-generate table of contents |
| **Quote** | `<blockquote>` | Nested quotes, rich text | Support multi-level nesting |
| **Code Block** | `<pre><code>` | Syntax highlighting, language identification | Requires theme support |
| **Divider** | `<hr>` | Style preservation | Full support |

#### 📋 Lists & Structure
| Notion Block Type | WordPress Conversion | Preserved Features | Notes |
|------------------|---------------------|-------------------|-------|
| **Bulleted List** | `<ul><li>` | Nested lists, rich text | Support multi-level nesting |
| **Numbered List** | `<ol><li>` | Numbering, nested lists | Auto-numbering |
| **To-do List** | Custom checkboxes | Checked state, nesting | CSS styling customizable |
| **Toggle List** | Collapsible components | Expanded state, nesting | Requires JavaScript support |

#### 🖼️ Media & Embeds
| Notion Block Type | WordPress Conversion | Processing Method | Notes |
|------------------|---------------------|------------------|-------|
| **Image** | WordPress media library | Auto-download, optimization | Support title and caption |
| **File** | Download links | Auto-download to media library | Check file size limits |
| **Video** | Embed code | YouTube, Vimeo, etc. | Maintain responsiveness |
| **Audio** | HTML5 audio player | Auto-download audio files | Support multiple formats |
| **PDF** | Embed viewer | Download + online preview | Requires plugin support |

#### 🔢 Advanced Content
| Notion Block Type | WordPress Conversion | Rendering Engine | Configuration Requirements |
|------------------|---------------------|-----------------|---------------------------|
| **Math Formula** | KaTeX rendering | KaTeX.js | Plugin built-in CDN & local resources |
| **Mermaid Charts** | SVG charts | Mermaid.js | Plugin built-in CDN & local resources |
| **Table** | HTML tables | Responsive tables | CSS styling customizable |
| **Database** | Simplified table view | Static HTML | No interaction support |
| **Synced Block** | Referenced content | Static snapshot | No real-time updates |

### 🎯 Content Conversion Configuration

#### 🖼️ Image Processing Strategy

**Auto-download Configuration:**
```
Settings → Media Processing → Image Download Strategy
```

**Configuration Options:**
- **File Size Limit:** Default 5MB, customizable
- **Supported Formats:** JPEG, PNG, GIF, WebP, SVG
- **Naming Rules:** Keep original name / Auto-rename / Custom rules
- **Storage Path:** Organize by year/month folders / Unified directory

**Optimization Options:**
- ✅ Auto-compress images
- ✅ Generate responsive images
- ✅ Add Alt text
- ✅ Preserve EXIF information

#### 🔗 Link Processing

**Internal Link Conversion:**
- Notion page links → WordPress post links
- Auto-update link targets
- Maintain link validity

**External Link Processing:**
- Keep original links
- Add `rel="noopener"` attribute
- Configurable to open in new window

#### 📊 Formula & Chart Configuration

**Math Formula Settings:**
```javascript
// KaTeX configuration example
{
  "displayMode": false,
  "throwOnError": false,
  "errorColor": "#cc0000",
  "macros": {
    "\\RR": "\\mathbb{R}",
    "\\NN": "\\mathbb{N}"
  }
}
```

**Mermaid Chart Settings:**
```javascript
// Mermaid configuration example
{
  "theme": "default",
  "themeVariables": {
    "primaryColor": "#ff0000"
  },
  "flowchart": {
    "useMaxWidth": true
  }
}
```

---

## 🔒 Security & Performance Optimization

### 🛡️ Security Settings

#### 🔐 Access Control

**API Security:**
- ✅ API key encrypted storage
- ✅ Request source verification
- ✅ Rate limiting protection
- ✅ Abnormal access monitoring

**Webhook Security:**
- ✅ Signature verification
- ✅ Timestamp checking
- ✅ Replay attack protection
- ✅ IP whitelist (optional)

#### 🚫 Content Security

**File Upload Restrictions:**
```
Settings → Security Options → File Upload Restrictions
```

**Configuration Items:**
- **Allowed File Types:** Images, documents, audio/video
- **File Size Limit:** Single file maximum 50MB
- **Filename Filtering:** Remove special characters
- **Virus Scanning:** Integrate third-party scanning services

**Content Filtering:**
- 🔍 Malicious script detection
- 🔍 SQL injection protection
- 🔍 XSS attack protection
- 🔍 Sensitive information filtering

### ⚡ Performance Optimization

#### 🚀 Sync Performance Optimization

**Incremental Sync Configuration:**
```
Settings → Performance Optimization → Enable Incremental Sync
```

**Optimization Effects:**
- 📈 Sync speed improvement 80%+
- 📉 Server load reduction 70%+
- 📉 Memory usage reduction 60%+
- 📉 Database queries reduction 90%+

**Batch Processing Settings:**
- **Batch Size:** Process 10-50 articles per batch
- **Processing Interval:** 1-5 second pause between batches
- **Timeout Settings:** Maximum execution time 300 seconds per batch

#### 💾 Caching Strategy

**Content Caching:**
- 🗄️ Notion API response caching
- 🗄️ Image download caching
- 🗄️ Conversion result caching
- 🗄️ Field mapping caching

**Cache Configuration:**
```
Settings → Cache Settings
```
- **Cache Duration:** 1-24 hours selectable
- **Cache Size:** Maximum 100MB
- **Auto Cleanup:** Regularly clean expired cache
- **Manual Cleanup:** One-click clear all cache

#### 📊 Performance Monitoring

**Monitoring Metrics:**
- ⏱️ Sync duration statistics
- 📈 Success rate statistics
- 💾 Memory usage monitoring
- 🔄 API call frequency

**Performance Reports:**
- 📋 Daily performance summary
- 📊 Performance trend charts
- ⚠️ Performance anomaly alerts
- 💡 Optimization suggestion tips

---

# 🆘 Part III: Problem Solving (Support & Help)

## ❓ Frequently Asked Questions

### 🚀 Installation & Configuration Issues

#### Q1: Plugin cannot be activated after installation, shows "Fatal error"
**A:** This is usually caused by PHP version or extension issues.

**Solution Steps:**
1. **Check PHP Version**
   ```bash
   php -v
   ```
   Ensure version ≥ 7.4

2. **Check Required Extensions**
   ```bash
   php -m | grep -E "(curl|mbstring|json|openssl)"
   ```
   Ensure all extensions are installed

3. **Check Memory Limit**
   ```php
   // Add to wp-config.php
   ini_set('memory_limit', '256M');
   ```

4. **View Detailed Error Information**
   - Enable WordPress debug mode
   - Check `wp-content/debug.log` file

#### Q2: Shows "Invalid token" or "unauthorized"
**A:** API key configuration issue.

**Solution Steps:**
1. **Regenerate API Key**
   - Visit [Notion Integrations](https://www.notion.so/my-integrations)
   - Select your integration → "Show" → "Regenerate"

2. **Check Key Format**
   - Ensure key starts with `secret_`
   - Length should be 50+ characters
   - No spaces or special characters

3. **Verify Integration Permissions**
   - Ensure "Read content" permission is enabled
   - Ensure integration is invited to target database

#### Q3: Cannot find database or shows "Database not accessible"
**A:** Database permissions or ID configuration issue.

**Solution Steps:**
1. **Verify Database ID**
   - Ensure ID length is 32 characters
   - No hyphens or other symbols
   - Copied from correct URL location

2. **Check Database Permissions**
   - On database page click "Share"
   - Ensure integration is invited
   - Ensure integration has "Can edit" permissions

3. **Test Connection**
   - Click "Test Connection" in plugin settings page
   - View detailed error information

### 🔄 Sync Related Issues

#### Q4: Some articles not syncing
**A:** Usually field mapping or article status issues.

**Troubleshooting Steps:**
1. **Check Article Status**
   - Ensure Notion status field is set correctly
   - Status values must match plugin configuration

2. **Verify Field Mapping**
   - Check if title field is empty
   - Ensure required fields have values

3. **View Sync Logs**
   - Enable Debug log level
   - View specific skip reasons

#### Q5: Images show 404 or cannot load
**A:** Image download or permission issues.

**Solutions:**
1. **Check Media Library Permissions**
   ```bash
   chmod 755 wp-content/uploads
   chown www-data:www-data wp-content/uploads -R
   ```

2. **Verify File Size Limits**
   - Check PHP `upload_max_filesize` setting
   - Check plugin file size limit configuration

3. **Re-sync Images**
   - Delete problematic articles
   - Re-execute sync

#### Q6: Math formulas or charts not displaying
**A:** Frontend resource loading issue.

**Solutions:**
1. **Check Theme Compatibility**
   - Ensure theme calls `wp_footer()` hook
   - Check for plugin conflicts

2. **Manually Load Resources**
   Add to theme's `functions.php`:
   ```php
   function load_notion_assets() {
       wp_enqueue_script('katex', 'https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.js');
       wp_enqueue_style('katex', 'https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.css');
       wp_enqueue_script('mermaid', 'https://cdn.jsdelivr.net/npm/mermaid@10.0.0/dist/mermaid.min.js');
   }
   add_action('wp_enqueue_scripts', 'load_notion_assets');
   ```

### ⚡ Webhook Related Issues

#### Q7: Webhook not triggering or significant delay
**A:** Network connection or configuration issue.

**Troubleshooting Steps:**
1. **Verify Website Accessibility**
   ```bash
   curl -X POST https://yoursite.com/wp-json/notion-to-wordpress/v1/webhook
   ```

2. **Check SSL Certificate**
   - Ensure certificate is valid and not expired
   - Use SSL detection tools to verify

3. **View Webhook Logs**
   - Check Webhook call records in plugin settings
   - Check Notion's Webhook status

#### Q8: Webhook verification fails
**A:** Signature verification or timestamp issue.

**Solutions:**
1. **Check Server Time**
   ```bash
   date
   ntpdate -s time.nist.gov
   ```

2. **Verify Webhook Configuration**
   - Ensure URL is completely correct
   - Check for redirects

3. **Temporarily Disable Verification**
   - Can temporarily disable signature verification during debugging
   - Re-enable after problem is resolved

### 🔧 Performance Related Issues

#### Q9: Sync very slow or times out
**A:** Server performance or configuration issue.

**Optimization Solutions:**
1. **Adjust PHP Configuration**
   ```ini
   max_execution_time = 300
   memory_limit = 512M
   max_input_vars = 3000
   ```

2. **Enable Incremental Sync**
   - Enable incremental sync in plugin settings
   - Reduce amount of data per sync

3. **Optimize Server Performance**
   - Use SSD drives
   - Increase server memory
   - Use PHP 7.4+ version

#### Q10: Website slows down during sync
**A:** Resource competition issue.

**Solutions:**
1. **Adjust Sync Frequency**
   - Reduce automatic sync frequency
   - Avoid syncing during peak hours

2. **Use Background Processing**
   - Enable WordPress Cron
   - Use queue processing for large amounts of data

3. **Monitor Resource Usage**
   - Use performance monitoring plugins
   - Regularly check server load

---

## 🐞 Systematic Troubleshooting

### 🔍 Diagnostic Process Overview

When encountering problems, please follow this systematic troubleshooting process:

```
1. Determine Problem Type → 2. Collect Diagnostic Information → 3. Execute Corresponding Solutions → 4. Verify Fix Results
```

### 📊 Problem Classification & Diagnosis

#### 🚨 Category 1: Installation & Activation Issues

**Symptom Identification:**
- Plugin cannot be uploaded or installed
- Fatal Error appears during activation
- Plugin doesn't appear in list or displays abnormally

**Diagnostic Steps:**
1. **Environment Check**
   ```bash
   # Check PHP version
   php -v

   # Check required extensions
   php -m | grep -E "(curl|mbstring|json|openssl)"

   # Check memory limit
   php -i | grep memory_limit
   ```

2. **Permission Check**
   ```bash
   # Check plugin directory permissions
   ls -la wp-content/plugins/

   # Check upload directory permissions
   ls -la wp-content/uploads/
   ```

3. **Log Analysis**
   - Enable WordPress debugging: Add to `wp-config.php`:
     ```php
     define('WP_DEBUG', true);
     define('WP_DEBUG_LOG', true);
     define('WP_DEBUG_DISPLAY', false);
     ```
   - Check error log: `wp-content/debug.log`

**Common Solutions:**
| Error Type | Solution | Verification Method |
|------------|----------|-------------------|
| PHP version too low | Upgrade to PHP 7.4+ | `php -v` confirm version |
| Missing extensions | Install curl, mbstring etc. extensions | `php -m` confirm extensions |
| Insufficient memory | Increase `memory_limit` to 256M+ | Check if plugin activates normally |
| Permission issues | Set correct file permissions | Re-upload plugin and test |

#### ⚡ Category 2: Connection & Authentication Issues

**Symptom Identification:**
- Shows "Invalid token" or "unauthorized"
- Cannot connect to Notion API
- Database access denied

**Diagnostic Tools:**
```bash
# Test network connection
curl -I https://api.notion.com/v1/users/me \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Notion-Version: 2022-06-28"

# Test database access
curl https://api.notion.com/v1/databases/YOUR_DATABASE_ID \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Notion-Version: 2022-06-28"
```

**Systematic Troubleshooting:**
1. **Verify API Key**
   - Ensure key format is correct
   - Ensure key hasn't expired or been revoked
   - Regenerate key for testing

2. **Verify Database Configuration**
   - Ensure database ID length is 32 characters
   - Ensure integration is invited to database
   - Ensure integration has correct permissions

3. **Network Connectivity Test**
   - Check if server can access api.notion.com
   - Check firewall settings
   - Verify SSL certificates

#### 🔄 Category 3: Sync Related Issues

**Symptom Identification:**
- Some or all content not syncing
- Sync process interrupted or failed
- Content format abnormal

**Diagnostic Process:**
1. **Enable Detailed Logging**
   ```
   Plugin Settings → Debug Options → Log Level → Debug
   ```

2. **Analyze Sync Logs**
   - Check `wp-content/uploads/notion-to-wordpress-logs/sync-*.log`
   - Identify specific error messages and failure reasons

3. **Step-by-step Troubleshooting**
   - Test single page sync
   - Check field mapping configuration
   - Verify Notion page status

**Solution Matrix:**
| Problem Type | Possible Cause | Solution Steps | Verification Method |
|--------------|----------------|----------------|-------------------|
| Content not syncing | Status field mismatch | Check and correct status values | Manual sync test |
| Images 404 | Download permission issue | Fix media library permissions | Re-sync images |
| Format abnormal | Theme CSS conflict | Add custom styles | Check frontend display |
| Sync timeout | Insufficient server performance | Optimize PHP configuration | Monitor sync time |

#### 🌐 Category 4: Webhook Related Issues

**Symptom Identification:**
- Webhook not triggering or delayed
- Verification fails
- Sync not timely

**Diagnostic Tools:**
```bash
# Test Webhook endpoint
curl -X POST https://yoursite.com/wp-json/notion-to-wordpress/v1/webhook \
  -H "Content-Type: application/json" \
  -d '{"test": "webhook"}'

# Check SSL certificate
openssl s_client -connect yoursite.com:443 -servername yoursite.com
```

**Troubleshooting Checklist:**
- [ ] Website publicly accessible
- [ ] SSL certificate valid
- [ ] Webhook URL configured correctly
- [ ] Notion integration Webhook settings correct
- [ ] Server time synchronized

### 🛠️ Advanced Diagnostic Tools

#### 📋 System Information Collection Script

Create a temporary page in WordPress admin and add the following code for system diagnosis:

```php
<?php
// System information diagnosis
echo "<h3>PHP Information</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Execution Time Limit: " . ini_get('max_execution_time') . "<br>";
echo "Upload Size Limit: " . ini_get('upload_max_filesize') . "<br>";

echo "<h3>WordPress Information</h3>";
echo "WordPress Version: " . get_bloginfo('version') . "<br>";
echo "Theme: " . get_template() . "<br>";
echo "Plugin Count: " . count(get_option('active_plugins')) . "<br>";

echo "<h3>Server Information</h3>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "Operating System: " . php_uname() . "<br>";

echo "<h3>Extension Check</h3>";
$required_extensions = ['curl', 'mbstring', 'json', 'openssl'];
foreach ($required_extensions as $ext) {
    echo $ext . ": " . (extension_loaded($ext) ? "✅ Installed" : "❌ Not Installed") . "<br>";
}
?>
```

#### 🔧 Auto-fix Script

```php
<?php
// Auto-fix common issues
function auto_fix_common_issues() {
    // Fix media library permissions
    $upload_dir = wp_upload_dir();
    if (!is_writable($upload_dir['basedir'])) {
        chmod($upload_dir['basedir'], 0755);
        echo "Fixed media library permissions<br>";
    }

    // Clear expired cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        echo "Cleared cache<br>";
    }

    // Regenerate rewrite rules
    flush_rewrite_rules();
    echo "Flushed rewrite rules<br>";
}

// Execute auto-fix
auto_fix_common_issues();
?>
```

### 📞 Getting Technical Support

If the above methods cannot resolve the issue, please collect information in the following format and seek technical support:

**Problem Report Template:**
```
【Environment Information】
- WordPress Version:
- PHP Version:
- Plugin Version:
- Theme Name:
- Server Type:

【Problem Description】
- Specific Symptoms:
- Occurrence Time:
- Reproduction Steps:
- Error Messages:

【Attempted Solutions】
- Solution 1:
- Solution 2:
- Results:

【Log Information】
(Please attach relevant error logs)
```

**Contact Methods:**
- GitHub Issues: [Submit Issue](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
- Discussion Forum: [Community Discussion](https://github.com/Frank-Loong/Notion-to-WordPress/discussions)

---

## 📝 Best Practices

### 🎯 Content Organization Best Practices

#### 📚 Notion Database Design Principles

**1. Field Naming Standards**
```
Recommended Naming:
✅ Title / 标题          - Clear and explicit
✅ Status / 状态         - Standardized
✅ Date / 日期           - Simple and understandable
✅ Categories / 分类     - Plural form

Avoid Naming:
❌ My Title              - Too personalized
❌ post_title_field     - Too technical
❌ Status123            - Contains numbers
❌ Cat                  - Too abbreviated
```

**2. Status Value Standardization**
```
Recommended unified status values:
📝 Draft Stage: Draft, 草稿, Unpublished
🔍 Review Stage: Review, 审核中, Under Review
✅ Published Status: Published, 已发布, Public
🔒 Private Content: Private, 私密
🗄️ Archived Content: Archived, 已归档
```

**3. Category and Tag Strategy**
- **Categories:** Use for main content classification, recommend no more than 10
- **Tags:** Use for detailed marking, can be more but should be systematic
- **Hierarchical Relations:** Avoid overly deep category levels, recommend maximum 3 levels

#### 🗂️ Content Structure Best Practices

**Article Structure Recommendations:**
```markdown
# Main Title (H1) - Only one per article

## Main Sections (H2) - Article's main parts

### Subsections (H3) - Subdivided content under sections

#### Detailed Explanations (H4) - Specific explanation points

- List items
- Related points

> Important tips or quotes

```Code examples```
```

### ⚡ Sync Strategy Best Practices

#### 🕐 Sync Frequency Selection Guide

**Choose based on website type:**

| Website Type | Recommended Frequency | Reason | Notes |
|-------------|----------------------|--------|-------|
| **Personal Blog** | Daily | Moderate update frequency, low resource consumption | Can execute during off-peak hours |
| **Corporate Website** | Daily | Relatively stable content, high professionalism requirements | Recommend setting during non-business hours |
| **News Website** | Twice daily or Webhook | Frequent content updates, high timeliness requirements | Need sufficient server resources |
| **Documentation Site** | Weekly | Less content changes, stability priority | Can combine with manual sync |
| **E-commerce Site** | Webhook real-time | Product information needs real-time updates | Ensure server stability |

#### 🎛️ Performance Optimization Strategy

**1. Content Optimization**
```
Image Optimization:
- Compress image size (recommend < 1MB)
- Use appropriate image formats (JPEG/PNG/WebP)
- Add meaningful Alt text

Content Structure:
- Avoid overly long single articles (recommend < 5000 words)
- Use heading levels reasonably
- Appropriate paragraphing for better readability
```

**2. Server Configuration Optimization**
```ini
# PHP configuration recommendations
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 50M
post_max_size = 50M
max_input_vars = 3000

# MySQL configuration recommendations
max_allowed_packet = 64M
innodb_buffer_pool_size = 256M
```

### 🔒 Security Best Practices

#### 🛡️ API Security Management

**1. Key Management**
- Regularly rotate API keys (recommend every 3-6 months)
- Don't hardcode keys in code or configuration files
- Use environment variables or encrypted storage
- Limit API key permission scope

**2. Access Control**
```php
// Set API key in wp-config.php
define('NOTION_API_TOKEN', getenv('NOTION_API_TOKEN'));

// Restrict plugin settings page access
if (!current_user_can('manage_options')) {
    wp_die('Insufficient permissions');
}
```

#### 🔐 Content Security

**1. Content Filtering**
- Enable content security scanning
- Filter malicious scripts and code
- Verify upload file types
- Limit file sizes

**2. Backup Strategy**
```
Backup frequency recommendations:
- Database: Daily backup
- Files: Weekly backup
- Complete backup: Monthly backup

Backup storage:
- Local backup + Cloud backup
- Multi-region storage
- Regular recovery testing
```

### 🎨 User Experience Best Practices

#### 📱 Responsive Design

**Ensure content displays properly on various devices:**
- Test mobile display effects
- Optimize image loading speed
- Ensure tables are scrollable on small screens
- Verify responsive effects of math formulas and charts

#### ⚡ Performance Optimization

**Frontend Optimization:**
```javascript
// Lazy load KaTeX and Mermaid
document.addEventListener('DOMContentLoaded', function() {
    // Only load when math formulas are needed
    if (document.querySelector('.math-formula')) {
        loadKaTeX();
    }

    // Only load when charts are needed
    if (document.querySelector('.mermaid')) {
        loadMermaid();
    }
});
```

### 🔄 Maintenance Best Practices

#### 📊 Monitoring and Maintenance

**1. Regular Check Checklist**
```
Weekly checks:
□ Check sync logs for errors
□ Verify website performance is normal
□ Check backup execution success
□ Verify SSL certificate not expiring soon

Monthly checks:
□ Check for plugin updates
□ Monitor server resource usage
□ Database optimization
□ Security scanning

Quarterly checks:
□ API key rotation
□ Backup recovery testing
□ Performance benchmark testing
□ User feedback collection
```

**2. Fault Prevention**
- Set up monitoring alerts
- Establish fault response procedures
- Prepare rollback plans
- Document operation procedures

#### 🎯 Continuous Optimization

**Data Analysis:**
- Monitor sync success rates
- Analyze performance bottlenecks
- Collect user feedback
- Optimize content structure

**Version Management:**
- Track plugin updates
- Test new features
- Maintain compatibility
- Record change logs

---

# 📚 Part IV: Resources & Extensions

## 🔗 Official Resource Links

### 📂 Project Core Resources

#### 🏠 Main Documentation
| Resource Type | Link | Description | Update Frequency |
|--------------|------|-------------|------------------|
| **GitHub Repository** | [Notion-to-WordPress](https://github.com/Frank-Loong/Notion-to-WordPress) | Source code, releases, issue tracking | Continuous updates |
| **Project Overview** | [PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md) | Current development status and feature comparison | Monthly updates |
| **Developer Guide** | [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) | Complete development and contribution guide | As needed updates |
| **Changelog** | [Commits](https://github.com/Frank-Loong/Notion-to-WordPress/commits) | Version history and detailed update records | Real-time updates |

#### 📦 Downloads & Releases
- **[Latest Version Download](https://github.com/Frank-Loong/Notion-to-WordPress/releases/latest)** - Get latest stable version
- **[All Version History](https://github.com/Frank-Loong/Notion-to-WordPress/releases)** - View all release versions
- **[Development Version](https://github.com/Frank-Loong/Notion-to-WordPress/archive/refs/heads/dev.zip)** - Get latest development version (not recommended for production)

### 🛠️ Technical Reference Resources

#### 📚 Official API Documentation
| Platform | Documentation Link | Key Content | Use Case |
|----------|-------------------|-------------|----------|
| **Notion API** | [developers.notion.com](https://developers.notion.com/) | API reference, authentication, limitations | Deep customization development |
| **WordPress API** | [developer.wordpress.org](https://developer.wordpress.org/) | Plugin development, hook system | Plugin extension development |
| **REST API** | [WordPress REST API](https://developer.wordpress.org/rest-api/) | RESTful interface design | API integration development |

#### 🎨 Frontend Technology Stack
| Technology | Official Documentation | Purpose | Configuration Requirements |
|------------|----------------------|---------|---------------------------|
| **KaTeX** | [katex.org](https://katex.org/) | Math formula rendering | Plugin built-in CDN & local resources |
| **Mermaid** | [mermaid-js.github.io](https://mermaid-js.github.io/) | Charts and flowcharts | Plugin built-in CDN & local resources |
| **Prism.js** | [prismjs.com](https://prismjs.com/) | Code syntax highlighting | Optional, enhances code display |

---

## 📖 Advanced Learning Materials

### 🎓 Recommended Learning Paths

#### 🚀 Beginner Path
1. **Notion Basics** → [Notion Official Help](https://www.notion.so/help)
2. **WordPress Basics** → [WordPress Official Tutorials](https://wordpress.org/support/)
3. **Plugin Usage** → This documentation quick start section
4. **Basic Troubleshooting** → This documentation FAQ section

#### 🔧 Advanced User Path
1. **API Understanding** → [Notion API Getting Started](https://developers.notion.com/docs/getting-started)
2. **Custom Development** → [WordPress Plugin Development](https://developer.wordpress.org/plugins/)
3. **Performance Optimization** → [WordPress Performance Guide](https://developer.wordpress.org/advanced-administration/performance/)
4. **Security Hardening** → [WordPress Security Best Practices](https://wordpress.org/support/article/hardening-wordpress/)

#### 👨‍💻 Developer Path
1. **Source Code Analysis** → [GitHub Repository](https://github.com/Frank-Loong/Notion-to-WordPress)
2. **Contribution Guide** → [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md)
3. **Deep API Integration** → [Notion API Reference](https://developers.notion.com/reference)
4. **Plugin Architecture** → [WordPress Plugin Architecture](https://developer.wordpress.org/plugins/plugin-basics/)

### 📚 Recommended Learning Resources

#### 📖 Online Tutorials
| Platform | Course Name | Difficulty | Duration | Key Content |
|----------|-------------|----------|----------|-------------|
| **Notion Official** | Notion API Quick Start | Beginner | 2 hours | API basics, authentication, basic operations |
| **WordPress.org** | Plugin Development Basics | Intermediate | 4 hours | Hook system, database operations |
| **MDN** | Modern JavaScript Tutorial | Intermediate | 8 hours | ES6+, asynchronous programming, DOM operations |
| **PHP.net** | PHP Official Tutorial | Beginner | 6 hours | PHP basics, object-oriented programming |

#### 🛠️ Useful Tools

**Development Tools:**
- **[Postman](https://www.postman.com/)** - API testing and debugging
- **[VS Code](https://code.visualstudio.com/)** - Code editor
- **[Git](https://git-scm.com/)** - Version control
- **[Composer](https://getcomposer.org/)** - PHP dependency management

**Debugging Tools:**
- **[Query Monitor](https://wordpress.org/plugins/query-monitor/)** - WordPress performance monitoring
- **[Debug Bar](https://wordpress.org/plugins/debug-bar/)** - WordPress debugging tools
- **[Xdebug](https://xdebug.org/)** - PHP debugger
- **[Browser DevTools](https://developer.chrome.com/docs/devtools/)** - Browser developer tools

**Testing Tools:**
- **[PHPUnit](https://phpunit.de/)** - PHP unit testing
- **[WP-CLI](https://wp-cli.org/)** - WordPress command line tools
- **[Selenium](https://selenium.dev/)** - Automated testing
- **[Jest](https://jestjs.io/)** - JavaScript testing framework

---

## 🤝 Community & Support

### 💬 Best Ways to Get Help

#### 🔍 Self-Help Solutions (Recommended Priority)
1. **📚 Consult Documentation** - This complete guide covers 90% of common issues
2. **🔍 Search Known Issues** - Search in [GitHub Issues](https://github.com/Frank-Loong/Notion-to-WordPress/issues)
3. **📊 Check Troubleshooting** - Use systematic troubleshooting process in this documentation
4. **🛠️ Use Diagnostic Tools** - Run built-in diagnostic and repair tools

#### 🤝 Community Support
| Platform | Use Case | Response Time | How to Participate |
|----------|----------|---------------|-------------------|
| **GitHub Discussions** | General discussion, experience sharing, feature suggestions | 1-3 days | [Join Discussion](https://github.com/Frank-Loong/Notion-to-WordPress/discussions) |
| **GitHub Issues** | Bug reports, feature requests | 1-7 days | [Submit Issue](https://github.com/Frank-Loong/Notion-to-WordPress/issues/new) |
| **WordPress Forum** | WordPress-related questions | 1-5 days | [WordPress Support Forum](https://wordpress.org/support/) |

### 📝 How to Ask Effective Questions

#### ✅ Good Question Example
```
Title: [Bug] Image download fails during sync - permission error

Environment Information:
- WordPress: 6.3.1
- PHP: 8.0.28
- Plugin Version: 1.1.0
- Theme: Twenty Twenty-Three
- Server: Apache 2.4

Problem Description:
During manual sync execution, text content syncs normally, but all images show 404 errors.
Error log shows: Permission denied: /wp-content/uploads/

Reproduction Steps:
1. Create page with images in Notion
2. Execute manual sync
3. View WordPress article, images show 404

Attempted Solutions:
- Checked wp-content/uploads permissions (set to 755)
- Reinstalled plugin
- Cleared all cache

Additional Information:
[Attach relevant error log screenshots]
```

#### ❌ Poor Question Example
```
Title: Not working

Plugin doesn't work, what to do?
```

### 🎯 Ways to Contribute

#### 💻 Code Contributions
- **Fork Project** → Create feature branch → Submit Pull Request
- **Follow Code Standards** → Add tests → Update documentation
- **Participate in Code Review** → Respond to feedback → Continuous improvement

#### 📚 Documentation Contributions
- **Improve Existing Documentation** - Fix errors, supplement content, optimize structure
- **Translate Documentation** - Help translate to other languages
- **Create Tutorials** - Share usage experience and best practices

#### 🐛 Issue Reporting
- **Detailed Problem Description** - Provide complete environment information and reproduction steps
- **Provide Log Information** - Attach relevant error logs and screenshots
- **Follow Up on Issue Status** - Respond promptly to developer inquiries

#### 💡 Feature Suggestions
- **Describe Use Cases** - Explain why this feature is needed
- **Provide Design Ideas** - If you have specific implementation thoughts
- **Consider Compatibility** - Ensure suggestions don't break existing functionality

### 🏆 Community Recognition

#### 🌟 Contributor Recognition
We appreciate all community members who contribute to the project:
- **Code Contributors** - Showcased in GitHub repository
- **Documentation Contributors** - Credited in documentation
- **Issue Reporters** - Help improve product quality
- **Community Supporters** - Help other users solve problems

#### 🎖️ Special Thanks
- **[NotionNext](https://github.com/tangly1024/NotionNext)** – Provided valuable technical references
- **[Elog](https://github.com/LetTTGACO/elog)** – Offered helpful inspiration
- **[notion-content](https://github.com/pchang78/notion-content)** – Supplied initial implementation ideas
- **WordPress Community** - Provided powerful plugin development framework
- **Notion Development Team** - Provided excellent API interface

---

### 📞 Contact Information

**Project Maintainer:** Frank Loong
**GitHub:** [@Frank-Loong](https://github.com/Frank-Loong)
**Project Homepage:** [Notion-to-WordPress](https://github.com/Frank-Loong/Notion-to-WordPress)

---

<div align="center">

**📚 User Guide End**

*Thank you for reading the Notion to WordPress User Guide!*

**[⬆️ Back to Top](#-notion-to-wordpress---complete-user-guide) • [🏠 Home](../README.md) • [📊 Project Overview](PROJECT_OVERVIEW.md) • [🚀 Developer Guide](DEVELOPER_GUIDE.md) • [🇨🇳 中文版本](Wiki.zh_CN.md)**

---

**🎉 If this guide helped you, please consider:**
- ⭐ Give the project a Star
- 🔄 Share with other users who need it
- 💬 Share your usage experience in the community
- 🐛 Report errors or improvement suggestions in documentation

</div>