CREATE TABLE User (
    User_ID INT PRIMARY KEY AUTO_INCREMENT,
    FName VARCHAR(255) NOT NULL,
    LName VARCHAR(255) NOT NULL,
    Email VARCHAR(255) UNIQUE NOT NULL,
    Username VARCHAR(255) UNIQUE NOT NULL,
    Password VARCHAR(255) NOT NULL,
    UserType ENUM('admin', 'staff') NOT NULL,
    Profile_Picture VARCHAR(255) NULL
);

CREATE TABLE Product (
    Product_ID INT PRIMARY KEY AUTO_INCREMENT,
    Product_Code VARCHAR(50) UNIQUE NOT NULL,
    Product_Name VARCHAR(255) NOT NULL,
    Product_Price DECIMAL(10, 2) NOT NULL,
    Product_Picture VARCHAR(255) NULL,
    Category VARCHAR(100) NULL,
    Subcategory VARCHAR(100) NULL
);

CREATE TABLE Login_Tracker (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    User_ID INT,
    Login_Time DATETIME,
    Seen TINYINT(1) DEFAULT 0,
    FOREIGN KEY (User_ID) REFERENCES User(User_ID)
);

CREATE TABLE Sales (
    Sales_ID INT PRIMARY KEY AUTO_INCREMENT,
    User_ID INT NOT NULL,
    Product_ID INT NOT NULL,
    Order_Code VARCHAR(50) NOT NULL,
    Customer_Name VARCHAR(255) NOT NULL,
    Payment_Method ENUM('Cash', 'Gcash') NOT NULL DEFAULT 'Cash',
    Quantity INT NOT NULL CHECK (Quantity > 0),
    Unit_Price DECIMAL(10, 2) NOT NULL,
    Subtotal DECIMAL(10, 2) GENERATED ALWAYS AS (Quantity * Unit_Price) STORED,
    Sales_Date DATETIME NOT NULL,
    FOREIGN KEY (User_ID) REFERENCES User(User_ID),
    FOREIGN KEY (Product_ID) REFERENCES Product(Product_ID)
);

CREATE TABLE Reports (
    Report_ID INT PRIMARY KEY AUTO_INCREMENT,
    User_ID INT NOT NULL,
    Sales_ID INT NOT NULL,
    Report_Type VARCHAR(100),
    Report_Date DATETIME DEFAULT CURRENT_TIMESTAMP,
    Report_Description TEXT,
    FOREIGN KEY (User_ID) REFERENCES User(User_ID),
    FOREIGN KEY (Sales_ID) REFERENCES Sales(Sales_ID)
);



