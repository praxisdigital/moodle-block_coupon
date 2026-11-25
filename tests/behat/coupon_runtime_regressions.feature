@block @block_coupon
Feature: Coupon pages handle input and stale request-user records

  Scenario: Open the coupon redemption form and submit an invalid code
    Given I log in as "admin"
    When I visit "/blocks/coupon/view/input_coupon.php"
    Then I should see "Input coupon"
    And I set the field "Coupon Code" to "UNKNOWNTESTCODE"
    And I press "Submit Coupon"
    And I should see "You have entered an invalid coupon code."

  Scenario: Follow a stale coupon request-user deletion link
    Given I log in as "admin"
    When I visit "/blocks/coupon/view/requests/admin.php?action=deleteuser&itemid=999999"
    Then I should see "This coupon request user could not be found. The link may be out of date."
