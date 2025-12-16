# vatcar-controller-bookings-plugin
VATCAR FIR Station Booking Plugin
## 📖 Overview
The VATCAR FIR Station Booking Plugin is a WordPress plugin designed to allow controllers in the VATCAR division to manage ATC station bookings. Originally developed for the Curaçao FIR, this plugin has been generalised to support the wider VATCAR region, providing a streamlined way to reserve, edit, and manage controller positions.

## ✨ Features
Station Booking Form – Reserve ATC positions through a simple, user-friendly interface.

Edit & Delete Bookings – Update or cancel existing reservations with ease.

Validation – Built-in checks to ensure booking data is valid.

Schedules – Display upcoming bookings in a structured format.

Custom Styling – Includes VATCAR-specific CSS for consistent branding.

Git Updater Support – Receive plugin updates directly from GitHub via Git Updater.

## 🛠️ Installation
Download or clone the repository into your WordPress plugins directory:

bash
wp-content/plugins/vatcar-fir-station-booking
Activate the plugin from the WordPress admin dashboard under Plugins.

Configure any required settings (if applicable).

## 🚀 Usage
Navigate to the booking page on your WordPress site.

Fill out the booking form to reserve a station.

Use the edit/delete options to manage your reservations.

Administrators can view and manage all bookings from the WordPress backend.

## 🔄 Automatic Updates with Git Updater
This plugin supports updates via Git Updater, a WordPress plugin that allows you to install and update plugins directly from Git repositories.

### Setup
Install and activate the Git Updater plugin.

Ensure this plugin is installed from its GitHub repository (savmon120/vatcar-controller-bookings-plugin).

Git Updater will automatically detect the repository and notify you when updates are available.

Update the plugin directly from the WordPress dashboard, just like any plugin from the official repository.

## 📂 File Structure
vatcar-fir-station-booking.php – Main plugin file.

includes/ – Core classes for booking, scheduling, and validation.

templates/ – Frontend forms (booking, edit, delete).

assets/css/ – Stylesheets for plugin UI.

## ⚙️ Requirements
WordPress 5.8+

PHP 7.4+

Git Updater plugin (optional, for automatic updates)

## 🤝 Contributing
Pull requests are welcome! For major changes, please open an issue first to discuss what you’d like to change.

## 📜 License
This project is licensed under the MIT License — see the LICENSE file for details.