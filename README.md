# Supermarket Shop Management System

## Project Description

This project is a database management system designed for a supermarket shop. It provides a comprehensive schema to manage customers, stores, suppliers, products, orders, invoices, and loyalty cards. The system includes triggers, functions, and materialized views to ensure data consistency, optimize performance, and provide useful insights.

The project is implemented using SQL and includes two interchangeable SQL dump files (`dump.sql` and `dump_29669A.sql`) that define the database schema, triggers, functions, and sample data.

## Features

- **Customer Management**: Manage customer details, loyalty cards, and purchase history.
- **Store Management**: Handle store details, inventory, and responsible managers.
- **Supplier Management**: Manage supplier details, product availability, and pricing.
- **Order and Invoice Management**: Track orders, invoices, and apply discounts based on loyalty points.
- **Triggers and Functions**: Ensure data consistency and automate processes like updating inventory, calculating totals, and managing loyalty points.
- **Materialized Views**: Provide optimized views for frequently accessed data, such as customers with more than 300 loyalty points and loyalty card history.

## Prerequisites

- PostgreSQL (version 12 or higher recommended)
- A PostgreSQL client (e.g., pgAdmin, psql)

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/NECKER55/supermarket_shop.git
   cd supermarket_shop
   ```

2. Set up the database:
   - Open your PostgreSQL client.
   - Create a new database (e.g., `supermarket_shop`).
   - Load one of the SQL dump files (`dump.sql` or `dump_29669A.sql`) into the database:
     ```bash
     psql -U <username> -d supermarket_shop -f dump.sql
     ```
     Replace `<username>` with your PostgreSQL username.

3. Verify the database schema and sample data have been loaded successfully.

## Usage

- Use the provided SQL functions to query data. For example:
  - Retrieve all orders for a specific supplier:
    ```sql
    SELECT * FROM get_ordini_fornitore('12345678901');
    ```
  - Check available discounts for a customer:
    ```sql
    SELECT * FROM verifica_sconto_disponibile('VRDLGI85B15F205X');
    ```

- Modify the database as needed using the provided schema and triggers.

## File Structure

- `dump.sql`: Main SQL dump file containing the database schema, triggers, functions, and sample data.
- `dump_29669A.sql`: Alternate SQL dump file with the same functionality as `dump.sql`.
- `sito/`: Contains PHP files for a web interface to interact with the database.

## Contributing

Contributions are welcome! Please fork the repository and submit a pull request with your changes.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.